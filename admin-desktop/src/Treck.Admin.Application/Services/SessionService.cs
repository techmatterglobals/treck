using System.Net;
using Treck.Admin.Application.Contracts;
using Treck.Admin.Application.Errors;
using Treck.Admin.Application.Models;

namespace Treck.Admin.Application.Services;

public sealed class SessionService
{
    private readonly ITreckAuthenticationApi _authentication;
    private readonly ITreckDesktopApi _desktop;
    private readonly IAccessTokenStore _tokens;

    public SessionService(ITreckAuthenticationApi authentication, ITreckDesktopApi desktop, IAccessTokenStore tokens)
    {
        _authentication = authentication;
        _desktop = desktop;
        _tokens = tokens;
    }

    public DesktopBootstrap? Current { get; private set; }

    public async Task<DesktopBootstrap?> RestoreAsync(CancellationToken cancellationToken = default)
    {
        if (string.IsNullOrWhiteSpace(await _tokens.ReadAsync(cancellationToken)))
        {
            return null;
        }

        try
        {
            Current = await _desktop.GetBootstrapAsync(cancellationToken);
            return Current;
        }
        catch (TreckApiException exception) when (exception.IsUnauthorized || exception.IsForbidden)
        {
            await ClearLocalSessionAsync(cancellationToken);
            throw;
        }
    }

    public async Task<DesktopBootstrap> SignInAsync(string email, string password,
        CancellationToken cancellationToken = default)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(email);
        ArgumentException.ThrowIfNullOrWhiteSpace(password);

        var login = await _authentication.LoginAsync(
            email.Trim(), password, $"Treck Admin on {Environment.MachineName}", cancellationToken);
        await _tokens.WriteAsync(login.Token, cancellationToken);

        try
        {
            Current = await _desktop.GetBootstrapAsync(cancellationToken);
            return Current;
        }
        catch
        {
            await ClearLocalSessionAsync(cancellationToken);
            throw;
        }
    }

    public async Task SignOutAsync(CancellationToken cancellationToken = default)
    {
        try
        {
            if (!string.IsNullOrWhiteSpace(await _tokens.ReadAsync(cancellationToken)))
            {
                await _authentication.LogoutAsync(cancellationToken);
            }
        }
        catch (TreckApiException exception) when (exception.StatusCode is HttpStatusCode.Unauthorized or HttpStatusCode.Forbidden)
        {
            // The server session is already unusable; local cleanup still wins.
        }
        finally
        {
            Current = null;
            await _tokens.ClearAsync(CancellationToken.None);
        }
    }

    private async Task ClearLocalSessionAsync(CancellationToken cancellationToken)
    {
        Current = null;
        await _tokens.ClearAsync(cancellationToken);
    }
}
