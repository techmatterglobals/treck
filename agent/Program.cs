using Serilog;
using Treck.Agent;
using Treck.Agent.Configuration;

// Bootstrap logger: captures anything that fails before the host (and the
// configured Serilog pipeline) is built.
Log.Logger = new LoggerConfiguration()
    .WriteTo.Console()
    .CreateBootstrapLogger();

try
{
    Log.Information("Treck Agent booting…");

    var builder = Host.CreateApplicationBuilder(args);

    // Run as a Windows Service (also runs fine as a console app for dev).
    builder.Services.AddWindowsService(options => options.ServiceName = "TreckAgent");

    // Structured logging (console + rolling compact-JSON file), configured
    // entirely from appsettings.json "Serilog" section.
    builder.Services.AddSerilog((services, loggerConfiguration) => loggerConfiguration
        .ReadFrom.Configuration(builder.Configuration)
        .ReadFrom.Services(services)
        .Enrich.FromLogContext()
        .Enrich.WithProperty("MachineName", Environment.MachineName));

    // Bind + validate configuration; fail fast at startup if invalid.
    builder.Services.AddOptions<AgentOptions>()
        .Bind(builder.Configuration.GetSection(AgentOptions.SectionName))
        .ValidateDataAnnotations()
        .ValidateOnStart();

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
