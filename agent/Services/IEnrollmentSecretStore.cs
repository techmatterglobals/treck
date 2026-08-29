namespace Treck.Agent.Services;

public interface IEnrollmentSecretStore
{
    string? TryLoad();

    void DeleteFileSecret();
}
