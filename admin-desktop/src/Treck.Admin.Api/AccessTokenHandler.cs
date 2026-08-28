using System.Net.Http.Headers;
using Treck.Admin.Application.Contracts;

namespace Treck.Admin.Api;

public sealed class AccessTokenHandler : DelegatingHandler
{
    private readonly IAccessTokenStore _tokens;

    public AccessTokenHandler(IAccessTokenStore tokens) => _tokens = tokens;

    protected override async Task<HttpResponseMessage> SendAsync(
        HttpRequestMessage request,
        CancellationToken cancellationToken)
    {
        var token = await _tokens.ReadAsync(cancellationToken);
        if (!string.IsNullOrWhiteSpace(token))
        {
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
        }

        return await base.SendAsync(request, cancellationToken);
    }
}
