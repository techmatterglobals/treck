using Treck.Admin.Application.Services;

namespace Treck.Admin.Api;

public sealed class OrganizationContextHandler : DelegatingHandler
{
    public const string HeaderName = "X-Treck-Organization-Id";
    private readonly CurrentOrganizationService _organizations;

    public OrganizationContextHandler(CurrentOrganizationService organizations)
    {
        _organizations = organizations;
    }

    protected override async Task<HttpResponseMessage> SendAsync(
        HttpRequestMessage request,
        CancellationToken cancellationToken)
    {
        if (!IsBootstrapRequest(request) && _organizations.SelectedOrganizationId is long organizationId)
        {
            request.Headers.Remove(HeaderName);
            request.Headers.Add(HeaderName, organizationId.ToString(System.Globalization.CultureInfo.InvariantCulture));
        }

        return await base.SendAsync(request, cancellationToken);
    }

    private static bool IsBootstrapRequest(HttpRequestMessage request) =>
        request.RequestUri?.OriginalString.EndsWith("api/v1/desktop/bootstrap", StringComparison.OrdinalIgnoreCase) is true;
}
