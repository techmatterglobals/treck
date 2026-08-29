using Treck.Admin.Application.Models;

namespace Treck.Admin.Application.Contracts;

public interface ITreckDesktopApi
{
    Task<DesktopBootstrap> GetBootstrapAsync(CancellationToken cancellationToken = default);

    Task<DesktopOverview> GetOverviewAsync(CancellationToken cancellationToken = default);

    Task<DesktopPresence> GetPresenceAsync(CancellationToken cancellationToken = default);

    Task<DesktopAgentHealth> GetAgentHealthAsync(CancellationToken cancellationToken = default);

    Task<EmployeeDetail> GetEmployeeAsync(long employeeId, CancellationToken cancellationToken = default);
}
