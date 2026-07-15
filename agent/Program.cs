using Treck.Agent;
using Treck.Agent.Configuration;
using Treck.Agent.Services;

var builder = Host.CreateApplicationBuilder(args);

// Run as a Windows Service (auto-start; see doc 17 for sc.exe install).
builder.Services.AddWindowsService(options => options.ServiceName = "TreckAgent");

builder.Services.Configure<AgentOptions>(
    builder.Configuration.GetSection(AgentOptions.SectionName));

// Singletons: state + sensors + classifier.
builder.Services.AddSingleton<TokenStore>();
builder.Services.AddSingleton<IdleDetector>();
builder.Services.AddSingleton<SessionMonitor>();
builder.Services.AddSingleton<ActivityTracker>();

// Typed HTTP client for the API.
builder.Services.AddHttpClient<ApiClient>();

builder.Services.AddHostedService<Worker>();

var host = builder.Build();
host.Run();
