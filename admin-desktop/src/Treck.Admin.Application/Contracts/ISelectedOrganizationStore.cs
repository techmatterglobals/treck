namespace Treck.Admin.Application.Contracts;

public interface ISelectedOrganizationStore
{
    Task<long?> ReadAsync(CancellationToken cancellationToken = default);

    Task WriteAsync(long organizationId, CancellationToken cancellationToken = default);

    Task ClearAsync(CancellationToken cancellationToken = default);
}
