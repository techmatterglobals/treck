namespace Treck.Admin.Application.Contracts;

public interface IAccessTokenStore
{
    Task<string?> ReadAsync(CancellationToken cancellationToken = default);

    Task WriteAsync(string token, CancellationToken cancellationToken = default);

    Task ClearAsync(CancellationToken cancellationToken = default);
}
