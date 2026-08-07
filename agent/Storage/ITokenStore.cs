namespace Treck.Agent.Storage;

/// <summary>Persists the device bearer token, encrypted at rest.</summary>
public interface ITokenStore
{
    bool HasToken { get; }

    /// <summary>Decrypts and returns the stored token, or null if absent/undecryptable.</summary>
    string? TryLoad();

    void Save(string token);

    void Clear();
}
