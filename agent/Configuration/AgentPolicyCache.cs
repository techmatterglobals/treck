using System.Text.Json;
using Microsoft.Extensions.Logging;
using Treck.Agent.Api;
using Treck.Agent.Models;
using Treck.Agent.Storage;

namespace Treck.Agent.Configuration;

public sealed class AgentPolicyCache : IAgentPolicyCache
{
    private const string FileName = "policy.json";
    private readonly string _path;
    private readonly ILogger<AgentPolicyCache> _logger;
    private readonly object _gate = new();

    public AgentPolicyCache(IStoragePathProvider paths, ILogger<AgentPolicyCache> logger)
    {
        _path = Path.Combine(paths.BaseDirectory, FileName);
        _logger = logger;
    }

    public AgentConfigResponse? TryLoad()
    {
        lock (_gate)
        {
            if (!File.Exists(_path))
            {
                return null;
            }

            try
            {
                using var stream = File.OpenRead(_path);
                return JsonSerializer.Deserialize<AgentConfigResponse>(stream, TreckApiClient.JsonOptions);
            }
            catch (Exception ex)
            {
                _logger.LogWarning(ex, "Could not read cached agent policy {Path}.", _path);
                return null;
            }
        }
    }

    public void Save(AgentConfigResponse config)
    {
        lock (_gate)
        {
            Directory.CreateDirectory(Path.GetDirectoryName(_path)!);
            var temp = _path + "." + Guid.NewGuid().ToString("N") + ".tmp";

            try
            {
                using (var stream = File.Create(temp))
                {
                    JsonSerializer.Serialize(stream, config, TreckApiClient.JsonOptions);
                    stream.Flush(true);
                }

                if (File.Exists(_path))
                {
                    File.Replace(temp, _path, null);
                }
                else
                {
                    File.Move(temp, _path);
                }
            }
            finally
            {
                if (File.Exists(temp))
                {
                    File.Delete(temp);
                }
            }
        }
    }
}
