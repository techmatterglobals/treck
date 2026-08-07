namespace Treck.Agent.Storage;

/// <summary>Provides a stable, persistent device identifier (UUID v4).</summary>
public interface IDeviceIdStore
{
    /// <summary>Returns the persisted device id, generating + storing one on first run.</summary>
    string GetOrCreate();
}
