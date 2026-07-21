using System.Net.Security;
using System.Security.Authentication;
using Microsoft.Extensions.Hosting.WindowsServices;
using Microsoft.Extensions.Options;
using Polly;
using Polly.Extensions.Http;
using Serilog;
using Treck.Agent;
using Treck.Agent.Activity;
using Treck.Agent.Api;
using Treck.Agent.Applications;
using Treck.Agent.Configuration;
using Treck.Agent.Offline;
using Treck.Agent.Screenshots;
using Treck.Agent.Security;
using Treck.Agent.Services;
using Treck.Agent.Sessions;
using Treck.Agent.Spooling;
using Treck.Agent.Storage;
using Treck.Agent.Sync;

// Bootstrap logger: captures anything that fails before the host is built.
Log.Logger = new LoggerConfiguration()
    .WriteTo.Console()
    .CreateBootstrapLogger();

try
{
    Log.Information("Treck Agent booting…");

    var builder = Host.CreateApplicationBuilder(args);

    // Phase 8: the service (session 0) launches this same binary with
    // --capture-helper into the interactive session to do screenshot + interactive
    // collection. --capture-helper-test runs a one-shot capture validation.
    var isCaptureHelperTest = args.Contains("--capture-helper-test");
    var isCaptureHelper = args.Contains("--capture-helper");

    // --- Windows Service hosting (M6) ---
    // The service *name* (SCM key) must match what deploy/install-service.ps1
    // registers. The display name and description are set at install time by
    // that script (the Windows service lifetime options only carry the name).
    // Console execution keeps working: AddWindowsService is a no-op when the
    // process is not launched by the Service Control Manager.
    builder.Services.AddWindowsService(options => options.ServiceName = "TreckAgent");

    // Give the hosted background services (Worker, SyncWorker) time to observe
    // the stop signal and drain a final sync cycle before the host is torn down.
    builder.Services.Configure<HostOptions>(options =>
    {
        options.ShutdownTimeout = TimeSpan.FromSeconds(30);
    });

    // Requirement 3: when running as a service the process working directory is
    // %WINDIR%\System32, so the relative file-sink path in appsettings.json would
    // point at a directory the service cannot write to. Redirect the file sink to
    // a stable, writable location (%ProgramData%\TreckAgent\logs) before Serilog
    // reads the configuration. Console execution keeps the relative "logs/" path.
    // (WriteTo index 1 is the File sink; see appsettings.json.)
    // The capture helper is launched by the service (not the SCM), so
    // IsWindowsService() is false for it, yet its working directory is still
    // Program Files (not user-writable). Redirect its log sink to ProgramData too,
    // to a distinct file so the two processes never contend on the same log.
    if (WindowsServiceHelpers.IsWindowsService() || isCaptureHelper || isCaptureHelperTest)
    {
        var logFile = isCaptureHelperTest ? "treck-agent-selftest-.jsonl"
            : isCaptureHelper ? "treck-agent-helper-.jsonl"
            : "treck-agent-.jsonl";
        builder.Configuration["Serilog:WriteTo:1:Args:path"] = ResolveServiceLogFilePath(builder.Configuration, logFile);
    }

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

    builder.Services.AddOptions<OfflineStoreOptions>()
        .Bind(builder.Configuration.GetSection(OfflineStoreOptions.SectionName))
        .ValidateDataAnnotations();

    builder.Services.AddOptions<ApplicationTrackingOptions>()
        .Bind(builder.Configuration.GetSection(ApplicationTrackingOptions.SectionName))
        .ValidateDataAnnotations();

    builder.Services.AddOptions<ScreenshotOptions>()
        .Bind(builder.Configuration.GetSection(ScreenshotOptions.SectionName))
        .ValidateDataAnnotations();

    // --- Session detection (event-driven, no polling) ---
    builder.Services.AddSingleton(TimeProvider.System);
    builder.Services.AddSingleton<ISessionMonitor, WindowsSessionMonitor>();

    // --- Idle detection + heartbeat scheduler (no API involvement) ---
    builder.Services.AddSingleton<IIdleDetector, WindowsIdleDetector>();
    builder.Services.AddSingleton<IHeartbeatScheduler, HeartbeatScheduler>();

    // --- Application usage tracking (Phase 7; WinEvent-driven, no polling) ---
    builder.Services.AddSingleton<IActiveWindowService, WindowsActiveWindowService>();
    builder.Services.AddSingleton<IApplicationSessionManager, ApplicationSessionManager>();
    builder.Services.AddSingleton<IApplicationTracker, WindowsApplicationTracker>();

    // --- Screenshot module (Phase 8; opt-in) ---
    // Capture must run in the interactive session; the service launches a helper
    // there (see the hosted-service wiring below).
    builder.Services.AddSingleton<IScreenshotCaptureService, WindowsScreenshotCaptureService>();
    builder.Services.AddSingleton<IScreenshotProcessingService, ScreenshotProcessingService>();
    builder.Services.AddSingleton<IScreenshotSyncService, ScreenshotSyncService>();
    builder.Services.AddSingleton<IInteractiveSessionLauncher, WindowsInteractiveSessionLauncher>();

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

    // --- Offline queue + sync (storage isolated from API via IEventUploader) ---
    builder.Services.AddSingleton<IOfflineEventStore, SqliteEventStore>();
    builder.Services.AddSingleton<IEventUploader, AgentEventUploader>();
    builder.Services.AddSingleton<ISyncService, SyncService>();

    // --- One-shot capture self-test (--capture-helper-test) ---
    if (isCaptureHelperTest)
    {
        Log.Information("Running one-shot capture self-test…");
        using var testHost = builder.Build();
        return ScreenshotSelfTest.Run(testHost.Services);
    }

    // --- Hosted services: mode-aware wiring (Phase 8) ---
    if (isCaptureHelper)
    {
        // Interactive capture/collection helper. It runs everything that must see
        // the user's desktop — screenshots, app-usage (foreground) and
        // heartbeat/idle — and spools each event for the service to upload.
        // Registration, sync, session monitoring and the offline queue stay in the
        // service.
        Log.Information(
            "Starting in capture-helper mode: user={User} session={Session} pid={Pid}.",
            Environment.UserName, System.Diagnostics.Process.GetCurrentProcess().SessionId, Environment.ProcessId);

        builder.Services.AddSingleton(new AgentRuntime { CollectInteractiveInProcess = false });
        builder.Services.AddSingleton(EventSource.Current(EventSource.InteractiveHelper, "TreckAgent(helper)"));
        builder.Services.AddSingleton<IAgentEventSpool, FileAgentEventSpool>();
        builder.Services.AddSingleton<IScreenshotSink, SpoolScreenshotSink>();
        builder.Services.AddHostedService<ScreenshotWorker>();
        builder.Services.AddHostedService<HeartbeatSpoolForwarder>();
        builder.Services.AddHostedService<ApplicationUsageSpoolForwarder>();
    }
    else if (WindowsServiceHelpers.IsWindowsService())
    {
        // Session-0 service: cannot see the user's desktop. Delegate all
        // interactive collection (screenshots + foreground + idle) to a helper it
        // launches into the active session, and ingest the helper's spool.
        builder.Services.AddSingleton(new AgentRuntime { CollectInteractiveInProcess = false });
        builder.Services.AddSingleton(EventSource.Current(EventSource.Service, "TreckAgent(service)"));
        builder.Services.AddHostedService<SyncWorker>();
        builder.Services.AddHostedService<Worker>();
        builder.Services.AddHostedService<ScreenshotHelperSupervisor>();
        builder.Services.AddHostedService<AgentEventSpoolWorker>();
    }
    else
    {
        // Console/dev: already interactive → collect + capture in-process.
        builder.Services.AddSingleton(new AgentRuntime { CollectInteractiveInProcess = true });
        builder.Services.AddSingleton(EventSource.Current(EventSource.Service, "TreckAgent(console)"));
        builder.Services.AddSingleton<IScreenshotSink, OfflineQueueScreenshotSink>();
        builder.Services.AddHostedService<SyncWorker>();
        builder.Services.AddHostedService<Worker>();
        builder.Services.AddHostedService<ScreenshotWorker>();
    }

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

// Resolves a log file path under the agent's writable data directory (mirrors
// StoragePathProvider: %ProgramData%\TreckAgent unless Agent:StoragePath
// overrides it) and ensures the directory exists. The file name distinguishes
// the service from the interactive capture helper so they never share a sink.
static string ResolveServiceLogFilePath(IConfiguration configuration, string fileName)
{
    var configuredStorage = configuration["Agent:StoragePath"];

    var baseDirectory = string.IsNullOrWhiteSpace(configuredStorage)
        ? Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "TreckAgent")
        : configuredStorage;

    var logDirectory = Path.Combine(baseDirectory, "logs");
    Directory.CreateDirectory(logDirectory);

    return Path.Combine(logDirectory, fileName);
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
