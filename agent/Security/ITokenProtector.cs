namespace Treck.Agent.Security;

/// <summary>Encrypts/decrypts sensitive strings (the device bearer token) at rest.</summary>
public interface ITokenProtector
{
    byte[] Protect(string plaintext);

    string Unprotect(byte[] cipher);
}
