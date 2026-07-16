using System.Net.Security;
using System.Security.Authentication;
using Microsoft.Extensions.Options;
using Polly;
using Polly.Extensions.Http;
using Serilog;
using Treck.Agent;
using Treck.Agent.Api;
using Treck.Agent.Configuration;
using Treck.Agent.Security;
using Treck.Agent.Services;
using Treck.Agent.Sessions;
using Treck.Agent.Storage;

// Bootstrap logger: captures anything that fails before the host is built.
Log.Logger = new LoggerConfiguration()
    .WriteTo.Console()
    .CreateBootstrapLogger();

try
{
    Log.Information("Treck Agent booting…");

    var builder = Host.CreateApplicationBuilder(args);

    builder.Services.AddWindowsService(options => options.ServiceName = "TreckAgent");

    builder.Services.AddSerilog((services, loggerConfiguration) => loggerConfiguration
        .ReadFrom.Configuration(builder.Configuration)
        .ReadFrom.Services(services)
        .Enrich.FromLogContext()
        .Enrich.WithProperty("MachineName", Environment.MachineName));

    builder.Services.AddOptions<AgentOptions>()
        .Bind(builder.Configuration.GetSection(AgentOptions.SectionName))
        .ValidateDataAnnotations()
        .ValidateOnStart();

    builder.Services.AddOptions<SessionMonitorOptions>()
        .Bind(builder.Configuration.GetSection(SessionMonitorOptions.SectionName))
        .ValidateDataAnnotations();

    // --- Session detection (event-driven, no polling) ---
    builder.Services.AddSingleton(TimeProvider.System);
    builder.Services.AddSingleton<ISessionMonitor, WindowsSessionMonitor>();

    // --- Storage / security ---
    builder.Services.AddSingleton<IStoragePathProvider, StoragePathProvider>();
    builder.Services.AddSingleton<ITokenProtector, DpapiTokenProtector>();
    builder.Services.AddSingleton<IDeviceIdStore, FileDeviceIdStore>();
    builder.Services.AddSingleton<ITokenStore, DpapiTokenStore>();

    // --- API client: IHttpClientFactory + Polly retry + TLS-validating handler ---
    builder.Services.AddHttpClient<ITreckApiClient, TreckApiClient>((serviceProvider, client) =>
        {
            var options = serviceProvider.GetRequiredService<IOptions<AgentOptions>>().Value;
            client.BaseAddress = new Uri(options.BaseUrl.TrimEnd('/') + "/");
            client.Timeout = TimeSpan.FromSeconds(30);
            client.DefaultRequestHeaders.Accept.Add(new("application/json"));
        })
        .ConfigurePrimaryHttpMessageHandler(() => new SocketsHttpHandler
        {
            // Requirement 3: rely on the default certificate chain validation
            // (no custom callback that would bypass it) and require modern TLS.
            SslOptions = new SslClientAuthenticationOptions
            {
                EnabledSslProtocols = SslProtocols.Tls12 | SslProtocols.Tls13,
            },
        })
        .AddPolicyHandler((serviceProvider, _) => BuildRetryPolicy(serviceProvider));

    // --- Registration orchestration ---
    builder.Services.AddSingleton<IDeviceRegistrationService, DeviceRegistrationService>();

    builder.Services.AddHostedService<Worker>();

    builder.Build().Run();

    return 0;
}
catch (Exception ex)
{
    Log.Fatal(ex, "Treck Agent terminated unexpectedly");

    return 1;
}
finally
{
    Log.CloseAndFlush();
}

// Requirement 2: Polly retry with exponential backoff on transient failures.
static IAsyncPolicy<HttpResponseMessage> BuildRetryPolicy(IServiceProvider serviceProvider)
{
    var logger = serviceProvider.GetRequiredService<ILoggerFactory>().CreateLogger("HttpRetry");
    var maxRetries = serviceProvider.GetRequiredService<IOptions<AgentOptions>>().Value.MaxRetries;

    return HttpPolicyExtensions
        .HandleTransientHttpError()                       // 5xx, 408, network failures
        .OrResult(response => (int)response.StatusCode == 429)
        .WaitAndRetryAsync(
            maxRetries,
            attempt => TimeSpan.FromSeconds(Math.Pow(2, attempt)), // 2s, 4s, 8s, …
            onRetry: (outcome, delay, attempt, _) => logger.LogWarning(
                "HTTP retry {Attempt}/{Max} after {Delay}s: {Reason}",
                attempt,
                maxRetries,
                delay.TotalSeconds,
                outcome.Exception?.Message ?? $"status {(int?)outcome.Result?.StatusCode}"));
}
