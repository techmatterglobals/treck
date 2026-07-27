using System.Text.Json;
using System.Text.Json.Nodes;
using Treck.Agent.Configuration;

namespace Treck.Agent.Spooling;

/// <summary>
/// Folds <see cref="EventSource"/> fields into an event's JSON payload so the
/// server can see where the event was collected without any schema change to the
/// agent_events pipeline (the payload is stored verbatim and parsed
/// case-tolerantly). Applied to heartbeat / app-usage / session payloads;
/// screenshots carry the same fields on their metadata + multipart form.
/// </summary>
public static class SourceStamp
{
    public static string Apply(string payloadJson, EventSource source)
    {
        JsonNode? node;
        try
        {
            node = JsonNode.Parse(payloadJson);
        }
        catch
        {
            node = null;
        }

        if (node is not JsonObject obj)
        {
            // Non-object payloads are left untouched rather than corrupted.
            return payloadJson;
        }

        obj["SourceSessionId"] = source.SessionId;
        obj["SourceUser"] = source.User;
        // Explicit alias for the backend's employee resolver (Phase 11). The
        // server maps computer + windows_username -> employee; keeping both keys
        // makes the wire contract self-describing while staying backward
        // compatible (older payloads carried only SourceUser).
        obj["WindowsUsername"] = source.User;
        obj["SourceProcess"] = source.Process;
        obj["CollectionMode"] = source.CollectionMode;

        return obj.ToJsonString(new JsonSerializerOptions());
    }
}
