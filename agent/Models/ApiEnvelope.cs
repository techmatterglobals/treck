namespace Treck.Agent.Models;

/// <summary>Every Laravel API response is wrapped as { "data": { ... } }.</summary>
public sealed record ApiEnvelope<T>(T Data);
