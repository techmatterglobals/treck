namespace Treck.Agent.Storage;

/// <summary>Resolves the base directory for persisted agent state.</summary>
public interface IStoragePathProvider
{
    /// <summary>Absolute base directory (created if missing).</summary>
    string BaseDirectory { get; }
}
