using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

namespace Treck.Agent.Services;

/// <summary>
/// Persists the Sanctum device token, the resolved employee/computer ids, and a
/// stable device UUID. The sensitive state is encrypted at rest with DPAPI
/// (LocalMachine scope) under %ProgramData%\TreckAgent. The device UUID is not
/// secret and is stored in plain text.
/// </summary>
public sealed class TokenStore
{
    private sealed record State(string Token, long EmployeeId, long ComputerId);

    private static readonly byte[] Entropy = Encoding.UTF8.GetBytes("Treck.Agent.v1");

    private readonly string _statePath;
    private readonly string _deviceIdPath;

    public string? Token { get; private set; }
    public long EmployeeId { get; private set; }
    public long ComputerId { get; private set; }
    public string DeviceUuid { get; }

    public bool HasToken => !string.IsNullOrEmpty(Token);

    public TokenStore()
    {
        var dir = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
            "TreckAgent");
        Directory.CreateDirectory(dir);

        _statePath = Path.Combine(dir, "state.dat");
        _deviceIdPath = Path.Combine(dir, "device.id");

        DeviceUuid = LoadOrCreateDeviceUuid();
        Load();
    }

    public void Save(string token, long employeeId, long computerId)
    {
        Token = token;
        EmployeeId = employeeId;
        ComputerId = computerId;

        var json = JsonSerializer.SerializeToUtf8Bytes(new State(token, employeeId, computerId));
        var encrypted = ProtectedData.Protect(json, Entropy, DataProtectionScope.LocalMachine);
        File.WriteAllBytes(_statePath, encrypted);
    }

    /// <summary>Forget the token (e.g. after a 401) to force re-registration.</summary>
    public void Clear()
    {
        Token = null;
        EmployeeId = 0;
        ComputerId = 0;

        if (File.Exists(_statePath))
        {
            File.Delete(_statePath);
        }
    }

    private void Load()
    {
        if (!File.Exists(_statePath))
        {
            return;
        }

        try
        {
            var encrypted = File.ReadAllBytes(_statePath);
            var json = ProtectedData.Unprotect(encrypted, Entropy, DataProtectionScope.LocalMachine);
            var state = JsonSerializer.Deserialize<State>(json);

            if (state is not null)
            {
                Token = state.Token;
                EmployeeId = state.EmployeeId;
                ComputerId = state.ComputerId;
            }
        }
        catch
        {
            // Corrupt/undecryptable state → treat as unregistered.
            Clear();
        }
    }

    private string LoadOrCreateDeviceUuid()
    {
        if (File.Exists(_deviceIdPath))
        {
            var existing = File.ReadAllText(_deviceIdPath).Trim();
            if (!string.IsNullOrEmpty(existing))
            {
                return existing;
            }
        }

        var uuid = Guid.NewGuid().ToString("N");
        File.WriteAllText(_deviceIdPath, uuid);
        return uuid;
    }
}
