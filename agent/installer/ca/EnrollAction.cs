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
    /// action never logs the code — only the agent's exit code. The code is passed
    /// to the child agent as an argument, so it is briefly visible on that single
    /// child process's command line during enrollment (documented limitation).
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

            // net472 has no ProcessStartInfo.ArgumentList; build a quoted string.
            // Codes are TRK-XXXX-XXXX-XXXX and URLs have no spaces/quotes; strip
            // any stray quotes defensively to avoid argument injection.
            var args = "--enroll " + Quote(code);
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
