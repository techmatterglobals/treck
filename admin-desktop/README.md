# Treck Admin Desktop

.NET 8 WPF administration client for the Treck Laravel backend.

## Projects

- `Treck.Admin.Desktop` — WPF composition root and views.
- `Treck.Admin.Application` — view models, use cases, and contracts.
- `Treck.Admin.Api` — typed Laravel API client.
- `Treck.Admin.Infrastructure` — Windows-specific credential storage and local services.

## Build on Windows

```powershell
dotnet restore Treck.Admin.Desktop.sln
dotnet build Treck.Admin.Desktop.sln -c Release
dotnet test Treck.Admin.Desktop.sln -c Release --no-build
```

The client authenticates through the Laravel Sanctum API, validates every new or
restored session through the desktop bootstrap contract, stores the bearer token
with current-user DPAPI, and builds navigation from server permissions and
feature flags. Passwords are never persisted.

The first live screens are implemented: organization/team overview KPIs poll
every 60 seconds, while the manager-scoped presence table polls every 30
seconds. Polling is cancelled on navigation and sign-out. Double-clicking a
presence row opens the authorized employee detail view.
