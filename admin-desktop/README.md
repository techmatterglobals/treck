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

The foundation shell is intentionally unauthenticated. Sign-in, bearer-token
injection, safe logout, and role-aware navigation belong to the next milestone.
