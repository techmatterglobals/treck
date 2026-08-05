using System.Globalization;
using Microsoft.Data.Sqlite;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Treck.Agent.Storage;

namespace Treck.Agent.Offline;

/// <summary>
/// SQLite-backed <see cref="IOfflineEventStore"/> (file: offline.db). Survives
/// restarts (durable file), keeps insertion order (autoincrement id), dedups by
/// a unique idempotency key, and enforces a max-row cap. Serialized with a lock
/// over a single connection (SQLite is single-writer).
/// </summary>
public sealed class SqliteEventStore : IOfflineEventStore, IDisposable
{
    private readonly string _connectionString;
    private readonly OfflineStoreOptions _options;
    private readonly ILogger<SqliteEventStore> _logger;
    private readonly TimeProvider _timeProvider;
    private readonly object _gate = new();

    private SqliteConnection? _connection;

    public SqliteEventStore(
        IStoragePathProvider paths,
        IOptions<OfflineStoreOptions> options,
        ILogger<SqliteEventStore> logger,
        TimeProvider timeProvider)
    {
        var dbPath = Path.Combine(paths.BaseDirectory, "offline.db");
        _connectionString = new SqliteConnectionStringBuilder { DataSource = dbPath }.ToString();
        _options = options.Value;
        _logger = logger;
        _timeProvider = timeProvider;
    }

    public void Initialize()
    {
        lock (_gate)
        {
            EnsureConnection();
        }
    }

    public long Enqueue(OfflineEvent offlineEvent)
    {
        lock (_gate)
        {
            EnsureConnection();

            using var cmd = _connection!.CreateCommand();
            cmd.CommandText =
                """
                INSERT OR IGNORE INTO events (idempotency_key, kind, payload, created_at, attempts)
                VALUES ($key, $kind, $payload, $created, 0);
                SELECT last_insert_rowid();
                """;
            cmd.Parameters.AddWithValue("$key", offlineEvent.IdempotencyKey);
            cmd.Parameters.AddWithValue("$kind", offlineEvent.Kind.ToString());
            cmd.Parameters.AddWithValue("$payload", offlineEvent.PayloadJson);
            cmd.Parameters.AddWithValue("$created", ToIso(offlineEvent.CreatedAtUtc));

            var id = Convert.ToInt64(cmd.ExecuteScalar() ?? 0L, CultureInfo.InvariantCulture);

            EnforceMaxRows();

            return id;
        }
    }

    public IReadOnlyList<OfflineEvent> GetPending(int limit)
    {
        lock (_gate)
        {
            EnsureConnection();

            using var cmd = _connection!.CreateCommand();
            cmd.CommandText =
                """
                SELECT id, idempotency_key, kind, payload, created_at, synced_at, attempts
                FROM events
                WHERE synced_at IS NULL
                ORDER BY id ASC
                LIMIT $limit;
                """;
            cmd.Parameters.AddWithValue("$limit", limit);

            using var reader = cmd.ExecuteReader();
            var results = new List<OfflineEvent>();
            while (reader.Read())
            {
                results.Add(Map(reader));
            }

            return results;
        }
    }

    public void MarkSynced(IEnumerable<long> ids)
    {
        var idList = ids.ToList();
        if (idList.Count == 0)
        {
            return;
        }

        lock (_gate)
        {
            EnsureConnection();

            using var transaction = _connection!.BeginTransaction();
            using var cmd = _connection.CreateCommand();
            cmd.Transaction = transaction;
            cmd.CommandText = "UPDATE events SET synced_at = $now WHERE id = $id;";
            var now = cmd.Parameters.Add("$now", SqliteType.Text);
            var id = cmd.Parameters.Add("$id", SqliteType.Integer);
            now.Value = ToIso(_timeProvider.GetUtcNow());

            foreach (var value in idList)
            {
                id.Value = value;
                cmd.ExecuteNonQuery();
            }

            transaction.Commit();
        }
    }

    public void RecordFailure(IEnumerable<long> ids, string error)
    {
        var idList = ids.ToList();
        if (idList.Count == 0)
        {
            return;
        }

        lock (_gate)
        {
            EnsureConnection();

            using var transaction = _connection!.BeginTransaction();
            using var cmd = _connection.CreateCommand();
            cmd.Transaction = transaction;
            cmd.CommandText = "UPDATE events SET attempts = attempts + 1, last_error = $err WHERE id = $id;";
            var err = cmd.Parameters.Add("$err", SqliteType.Text);
            var id = cmd.Parameters.Add("$id", SqliteType.Integer);
            err.Value = error.Length > 500 ? error[..500] : error;

            foreach (var value in idList)
            {
                id.Value = value;
                cmd.ExecuteNonQuery();
            }

            transaction.Commit();
        }
    }

    public void Drop(long id)
    {
        lock (_gate)
        {
            EnsureConnection();

            using var cmd = _connection!.CreateCommand();
            cmd.CommandText = "DELETE FROM events WHERE id = $id;";
            cmd.Parameters.AddWithValue("$id", id);
            cmd.ExecuteNonQuery();
        }
    }

    public int CountPending()
    {
        lock (_gate)
        {
            EnsureConnection();
            return (int)ScalarLong("SELECT COUNT(*) FROM events WHERE synced_at IS NULL;");
        }
    }

    public int Prune()
    {
        lock (_gate)
        {
            EnsureConnection();

            int removed;
            using (var cmd = _connection!.CreateCommand())
            {
                cmd.CommandText = "DELETE FROM events WHERE synced_at IS NOT NULL AND synced_at < $cutoff;";
                cmd.Parameters.AddWithValue("$cutoff", ToIso(_timeProvider.GetUtcNow().AddHours(-_options.RetentionHours)));
                removed = cmd.ExecuteNonQuery();
            }

            removed += EnforceMaxRows();
            return removed;
        }
    }

    // --- internals (all callers hold _gate) ---

    private void EnsureConnection()
    {
        if (_connection is not null)
        {
            return;
        }

        _connection = new SqliteConnection(_connectionString);
        _connection.Open();

        Execute("PRAGMA journal_mode=WAL;");
        Execute(
            """
            CREATE TABLE IF NOT EXISTS events (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                idempotency_key TEXT NOT NULL UNIQUE,
                kind            TEXT NOT NULL,
                payload         TEXT NOT NULL,
                created_at      TEXT NOT NULL,
                synced_at       TEXT NULL,
                attempts        INTEGER NOT NULL DEFAULT 0,
                last_error      TEXT NULL
            );
            """);
        Execute("CREATE INDEX IF NOT EXISTS idx_events_pending ON events(synced_at, id);");
    }

    private int EnforceMaxRows()
    {
        var total = (int)ScalarLong("SELECT COUNT(*) FROM events;");
        var over = total - _options.MaxRows;
        if (over <= 0)
        {
            return 0;
        }

        using var cmd = _connection!.CreateCommand();
        // Prefer dropping already-synced rows; fall back to oldest pending.
        cmd.CommandText =
            """
            DELETE FROM events WHERE id IN (
                SELECT id FROM events ORDER BY (synced_at IS NULL) ASC, id ASC LIMIT $n
            );
            """;
        cmd.Parameters.AddWithValue("$n", over);
        var removed = cmd.ExecuteNonQuery();

        if (removed > 0)
        {
            _logger.LogWarning(
                "Offline store exceeded MaxRows ({Max}); dropped {Removed} oldest record(s).",
                _options.MaxRows, removed);
        }

        return removed;
    }

    private void Execute(string sql)
    {
        using var cmd = _connection!.CreateCommand();
        cmd.CommandText = sql;
        cmd.ExecuteNonQuery();
    }

    private long ScalarLong(string sql)
    {
        using var cmd = _connection!.CreateCommand();
        cmd.CommandText = sql;
        return Convert.ToInt64(cmd.ExecuteScalar() ?? 0L, CultureInfo.InvariantCulture);
    }

    private static OfflineEvent Map(SqliteDataReader reader)
    {
        var syncedOrdinal = 5;
        return new OfflineEvent
        {
            Id = reader.GetInt64(0),
            IdempotencyKey = reader.GetString(1),
            Kind = Enum.Parse<OfflineEventKind>(reader.GetString(2)),
            PayloadJson = reader.GetString(3),
            CreatedAtUtc = ParseIso(reader.GetString(4)),
            SyncedAtUtc = reader.IsDBNull(syncedOrdinal) ? null : ParseIso(reader.GetString(syncedOrdinal)),
            Attempts = reader.GetInt32(6),
        };
    }

    private static string ToIso(DateTimeOffset value)
        => value.UtcDateTime.ToString("O", CultureInfo.InvariantCulture);

    private static DateTimeOffset ParseIso(string value)
        => DateTimeOffset.Parse(value, CultureInfo.InvariantCulture, DateTimeStyles.RoundtripKind);

    public void Dispose()
    {
        lock (_gate)
        {
            _connection?.Dispose();
            _connection = null;
        }
    }
}
