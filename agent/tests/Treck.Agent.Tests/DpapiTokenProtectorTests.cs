using System.Text;
using Treck.Agent.Security;
using Xunit;

namespace Treck.Agent.Tests;

public class DpapiTokenProtectorTests
{
    [Fact]
    public void Protect_then_Unprotect_round_trips_the_token()
    {
        if (!OperatingSystem.IsWindows())
        {
            return; // DPAPI (ProtectedData) is Windows-only.
        }

        var protector = new DpapiTokenProtector();
        const string token = "12|abcDEF0123456789secret";

        var cipher = protector.Protect(token);

        Assert.Equal(token, protector.Unprotect(cipher));
    }

    [Fact]
    public void Protect_does_not_store_plaintext()
    {
        if (!OperatingSystem.IsWindows())
        {
            return;
        }

        var protector = new DpapiTokenProtector();
        const string token = "12|super-secret-token";

        var cipher = protector.Protect(token);

        Assert.NotEqual(token, Encoding.UTF8.GetString(cipher));
        Assert.DoesNotContain("super-secret-token", Encoding.UTF8.GetString(cipher));
    }
}
