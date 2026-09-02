using System;
using System.Diagnostics;
using WixToolset.Dtf.WindowsInstaller;

namespace Treck.Agent.Installer.CA
{
    /// <summary>
    /// Deferred custom action that enrolls the computer by invoking the agent's
    /// own one-shot enrollment (<c>TreckAgent.exe --enroll &lt;code&gt;</c>) BEFORE
    /// the service is started. Runs as LocalSystem (deferred, non-impersonated),
    /// matching the service account and the DPAPI LocalMachine scope, so the
    /// token it stores is usable by the service.
    ///
    /// SECURITY: the enrollment code arrives via CustomActionData (its property is
    /// listed in MsiHiddenProperties, so it is never written to the MSI log). This
    /// action never logs the code — only the agent's exit code. The code is handed
    /// to the child agent through a process-scoped environment variable
    /// (TRECK_ENROLLMENT_CODE), never on the command line, so it is not visible via
    /// Process Explorer / WMI and is not persisted anywhere.
    /// </summary>
    public static class EnrollActions
    {
        // Must match Treck.Agent.Services.EnrollmentExitCode.
        private const int Success = 0;
        private const int AlreadyEnrolled = 2;

        [CustomAction]
        public static ActionResult EnrollDevice(Session session)
        {
            var data = session.CustomActionData;
            var exe = data.ContainsKey("Exe") ? data["Exe"] : null;
            var code = data.ContainsKey("Code") ? data["Code"] : null;
            var baseUrl = data.ContainsKey("BaseUrl") ? data["BaseUrl"] : null;

            if (string.IsNullOrWhiteSpace(exe))
            {
                session.Log("EnrollDevice: agent executable path was not provided.");
                return ActionResult.Failure;
            }

            if (string.IsNullOrWhiteSpace(code))
            {
                session.Log("EnrollDevice: no enrollment code was provided.");
                return ActionResult.Failure;
            }

            // Pass the code to the agent via a process-scoped environment variable
            // so it never appears on the child's command line (not visible in
            // Process Explorer / WMI). The variable lives only for this child
            // process and its lifetime, and is not persisted anywhere. The agent's
            // --enroll path reads TRECK_ENROLLMENT_CODE when no code argument is
            // given. Only the (space/quote-free) --base-url is passed as an arg.
            // net472 has no ProcessStartInfo.ArgumentList; build a quoted string.
            var args = "--enroll";
            if (!string.IsNullOrWhiteSpace(baseUrl))
            {
                args += " --base-url " + Quote(baseUrl);
            }

            var startInfo = new ProcessStartInfo(exe)
            {
                Arguments = args,
                UseShellExecute = false,
                CreateNoWindow = true,
            };

            // EnvironmentVariables requires UseShellExecute = false (set above).
            startInfo.EnvironmentVariables["TRECK_ENROLLMENT_CODE"] = code;

            try
            {
                using (var process = Process.Start(startInfo))
                {
                    if (process == null)
                    {
                        session.Log("EnrollDevice: failed to start the agent process.");
                        return ActionResult.Failure;
                    }

                    if (!process.WaitForExit(120000))
                    {
                        try { process.Kill(); } catch { /* best effort */ }
                        session.Log("EnrollDevice: enrollment timed out after 120s.");
                        return ActionResult.Failure;
                    }

                    var exit = process.ExitCode;
                    session.Log("EnrollDevice: agent exited with code " + exit + ".");

                    // 0 = enrolled; 2 = already enrolled (treat as success so a
                    // re-run/repair does not fail). Anything else fails the install.
                    return (exit == Success || exit == AlreadyEnrolled)
                        ? ActionResult.Success
                        : ActionResult.Failure;
                }
            }
            catch (Exception ex)
            {
                // Log the type/message only — never the code or arguments.
                session.Log("EnrollDevice: enrollment threw " + ex.GetType().Name + ": " + ex.Message);
                return ActionResult.Failure;
            }
        }

        private static string Quote(string value)
        {
            return "\"" + value.Replace("\"", string.Empty) + "\"";
        }
    }
}
