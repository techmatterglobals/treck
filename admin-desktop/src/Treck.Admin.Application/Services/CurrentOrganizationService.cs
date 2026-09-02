using Treck.Admin.Application.Contracts;
using Treck.Admin.Application.Models;

namespace Treck.Admin.Application.Services;

public sealed class CurrentOrganizationService
{
    private readonly ISelectedOrganizationStore _store;

    public CurrentOrganizationService(ISelectedOrganizationStore store)
    {
        _store = store;
    }

    public DesktopOrganization? Selected { get; private set; }
    public long? SelectedOrganizationId => Selected?.Id;
    public int Generation { get; private set; }
    public event Action? Changed;

    public async Task RevalidateAsync(DesktopBootstrap bootstrap, CancellationToken cancellationToken = default)
    {
        var savedId = await _store.ReadAsync(cancellationToken);
        var organization = savedId is long id
            ? bootstrap.Organizations.FirstOrDefault(candidate => candidate.Id == id)
            : null;

        organization ??= bootstrap.RecommendedOrganization;

        if (organization is null)
        {
            await ClearSelectionAsync(cancellationToken);
            return;
        }

        await SelectAsync(organization, cancellationToken);
    }

    public async Task SelectAsync(DesktopOrganization organization, CancellationToken cancellationToken = default)
    {
        Selected = organization;
        Generation++;
        await _store.WriteAsync(organization.Id, cancellationToken);
        Changed?.Invoke();
    }

    public async Task ClearSelectionAsync(CancellationToken cancellationToken = default)
    {
        Selected = null;
        Generation++;
        await _store.ClearAsync(cancellationToken);
        Changed?.Invoke();
    }
}
