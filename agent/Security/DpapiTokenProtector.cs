using System.Runtime.Versioning;
using System.Security.Cryptography;
using System.Text;

namespace Treck.Agent.Security;

/// <summary>
/// Windows DPAPI implementation of <see cref="ITokenProtector"/>. Uses the
/// LocalMachine scope (the agent runs as a machine service) with an additional
/// entropy value, so the ciphertext can only be decrypted on this machine.
/// The plaintext bearer token is never written to disk — only Protect() output.
/// </summary>
[SupportedOSPlatform("windows")]
public sealed class DpapiTokenProtector : ITokenProtector
{
    // Extra entropy mixed into the DPAPI key. Not a secret by itself; it scopes
    // decryption to callers that know this value.
    private static readonly byte[] Entropy = Encoding.UTF8.GetBytes("Treck.Agent.DeviceToken.v1");

    public byte[] Protect(string plaintext)
        => ProtectedData.Protect(Encoding.UTF8.GetBytes(plaintext), Entropy, DataProtectionScope.LocalMachine);

    public string Unprotect(byte[] cipher)
        => Encoding.UTF8.GetString(ProtectedData.Unprotect(cipher, Entropy, DataProtectionScope.LocalMachine));
}
