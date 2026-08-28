using Treck.Agent.Offline;

namespace Treck.Agent.Sync;

/// <summary>
/// The API boundary for shipping a queued event. Kept separate from the store so
/// persistence and communication stay decoupled. Returns true only when the
/// server acknowledged the event.
/// </summary>
public interface IEventUploader
{
    Task<bool> TryUploadAsync(OfflineEvent offlineEvent, CancellationToken cancellationToken);
}
