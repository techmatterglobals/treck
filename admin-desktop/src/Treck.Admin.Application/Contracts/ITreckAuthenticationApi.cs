using Treck.Admin.Application.Models;

namespace Treck.Admin.Application.Contracts;

public interface ITreckAuthenticationApi
{
    Task<LoginSession> LoginAsync(string email, string password, string deviceName,
        CancellationToken cancellationToken = default);

    Task LogoutAsync(CancellationToken cancellationToken = default);
}
