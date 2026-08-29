using Treck.Agent.Models;

namespace Treck.Agent.Configuration;

public interface IAgentPolicyCache
{
    AgentConfigResponse? TryLoad();

    void Save(AgentConfigResponse config);
}
