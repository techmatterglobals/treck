using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using Microsoft.Extensions.Logging;

namespace Treck.Agent.Screenshots;

/// <summary>
/// Launches a process into the active interactive console session using the
/// classic service→session pattern:
///
///   WTSGetActiveConsoleSessionId → WTSQueryUserToken → DuplicateTokenEx
///   → CreateEnvironmentBlock → CreateProcessAsUser (lpDesktop = winsta0\default)
///
/// Requires the caller to hold SeTcbPrivilege, which the LocalSystem service
/// account has. Off Windows / outside a service this is never used (console mode
/// captures in-process).
/// </summary>
[SupportedOSPlatform("windows")]
public sealed class WindowsInteractiveSessionLauncher : IInteractiveSessionLauncher
{
    private const uint INVALID_SESSION = 0xFFFFFFFF;

    private readonly ILogger<WindowsInteractiveSessionLauncher> _logger;

    public WindowsInteractiveSessionLauncher(ILogger<WindowsInteractiveSessionLauncher> logger)
    {
        _logger = logger;
    }

    public uint ActiveConsoleSessionId => WTSGetActiveConsoleSessionId();

    public ILaunchedProcess? Launch(string executablePath, string arguments)
    {
        var sessionId = WTSGetActiveConsoleSessionId();
        if (sessionId == INVALID_SESSION)
        {
            _logger.LogWarning("No active console session; cannot launch the capture helper yet.");
            return null;
        }

        if (!WTSQueryUserToken(sessionId, out var userToken))
        {
            _logger.LogWarning("WTSQueryUserToken failed for session {Session} (win32={Err}).", sessionId, Marshal.GetLastWin32Error());
            return null;
        }

        var duplicated = IntPtr.Zero;
        var environment = IntPtr.Zero;

        try
        {
            if (!DuplicateTokenEx(userToken, MAXIMUM_ALLOWED, IntPtr.Zero, SecurityImpersonation, TokenPrimary, out duplicated))
            {
                _logger.LogWarning("DuplicateTokenEx failed (win32={Err}).", Marshal.GetLastWin32Error());
                return null;
            }

            if (!CreateEnvironmentBlock(out environment, duplicated, false))
            {
                environment = IntPtr.Zero; // proceed without a per-user block
            }

            var startupInfo = new STARTUPINFO
            {
                cb = Marshal.SizeOf<STARTUPINFO>(),
                lpDesktop = @"winsta0\default",
            };

            var commandLine = $"\"{executablePath}\" {arguments}";
            var flags = CREATE_UNICODE_ENVIRONMENT | CREATE_NO_WINDOW;

            if (!CreateProcessAsUser(
                    duplicated, null, commandLine, IntPtr.Zero, IntPtr.Zero, false,
                    flags, environment, Path.GetDirectoryName(executablePath), ref startupInfo, out var processInfo))
            {
                _logger.LogWarning("CreateProcessAsUser failed (win32={Err}).", Marshal.GetLastWin32Error());
                return null;
            }

            CloseHandle(processInfo.hThread);

            _logger.LogInformation(
                "Capture helper launched: session={Session} user={User} pid={Pid} desktop=winsta0\\default.",
                sessionId, GetSessionUserName(sessionId), processInfo.dwProcessId);

            return new LaunchedProcess(processInfo.hProcess, sessionId);
        }
        finally
        {
            if (environment != IntPtr.Zero)
            {
                DestroyEnvironmentBlock(environment);
            }

            if (duplicated != IntPtr.Zero)
            {
                CloseHandle(duplicated);
            }

            CloseHandle(userToken);
        }
    }

    // ---- Launched process handle ------------------------------------------

    private sealed class LaunchedProcess : ILaunchedProcess
    {
        private IntPtr _handle;

        public LaunchedProcess(IntPtr handle, uint sessionId)
        {
            _handle = handle;
            SessionId = sessionId;
        }

        public uint SessionId { get; }

        public bool IsRunning => _handle != IntPtr.Zero && WaitForSingleObject(_handle, 0) == WAIT_TIMEOUT;

        public void Terminate()
        {
            if (_handle != IntPtr.Zero)
            {
                TerminateProcess(_handle, 0);
            }
        }

        public void Dispose()
        {
            if (_handle != IntPtr.Zero)
            {
                CloseHandle(_handle);
                _handle = IntPtr.Zero;
            }
        }
    }

    /// <summary>Domain\user of the session, for diagnostics; "(unknown)" on failure.</summary>
    private string GetSessionUserName(uint sessionId)
    {
        var domain = QuerySessionString(sessionId, WTS_DOMAIN_NAME);
        var user = QuerySessionString(sessionId, WTS_USER_NAME);

        if (string.IsNullOrEmpty(user))
        {
            return "(unknown)";
        }

        return string.IsNullOrEmpty(domain) ? user : $"{domain}\\{user}";
    }

    private string QuerySessionString(uint sessionId, int infoClass)
    {
        if (!WTSQuerySessionInformation(IntPtr.Zero, sessionId, infoClass, out var buffer, out _) || buffer == IntPtr.Zero)
        {
            return string.Empty;
        }

        try
        {
            return Marshal.PtrToStringUni(buffer) ?? string.Empty;
        }
        finally
        {
            WTSFreeMemory(buffer);
        }
    }

    // ---- Native interop ----------------------------------------------------

    private const uint MAXIMUM_ALLOWED = 0x02000000;
    private const int WTS_USER_NAME = 5;
    private const int WTS_DOMAIN_NAME = 7;
    private const int SecurityImpersonation = 2;
    private const int TokenPrimary = 1;
    private const uint CREATE_UNICODE_ENVIRONMENT = 0x00000400;
    private const uint CREATE_NO_WINDOW = 0x08000000;
    private const uint WAIT_TIMEOUT = 0x00000102;

    [StructLayout(LayoutKind.Sequential)]
    private struct PROCESS_INFORMATION
    {
        public IntPtr hProcess;
        public IntPtr hThread;
        public uint dwProcessId;
        public uint dwThreadId;
    }

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct STARTUPINFO
    {
        public int cb;
        public string? lpReserved;
        public string? lpDesktop;
        public string? lpTitle;
        public int dwX;
        public int dwY;
        public int dwXSize;
        public int dwYSize;
        public int dwXCountChars;
        public int dwYCountChars;
        public int dwFillAttribute;
        public int dwFlags;
        public short wShowWindow;
        public short cbReserved2;
        public IntPtr lpReserved2;
        public IntPtr hStdInput;
        public IntPtr hStdOutput;
        public IntPtr hStdError;
    }

    [DllImport("kernel32.dll")]
    private static extern uint WTSGetActiveConsoleSessionId();

    [DllImport("wtsapi32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool WTSQueryUserToken(uint sessionId, out IntPtr phToken);

    [DllImport("wtsapi32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool WTSQuerySessionInformation(
        IntPtr hServer, uint sessionId, int wtsInfoClass, out IntPtr ppBuffer, out uint pBytesReturned);

    [DllImport("wtsapi32.dll")]
    private static extern void WTSFreeMemory(IntPtr pMemory);

    [DllImport("advapi32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool DuplicateTokenEx(
        IntPtr hExistingToken, uint dwDesiredAccess, IntPtr lpTokenAttributes,
        int impersonationLevel, int tokenType, out IntPtr phNewToken);

    [DllImport("userenv.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CreateEnvironmentBlock(out IntPtr lpEnvironment, IntPtr hToken, bool bInherit);

    [DllImport("userenv.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool DestroyEnvironmentBlock(IntPtr lpEnvironment);

    [DllImport("advapi32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CreateProcessAsUser(
        IntPtr hToken, string? lpApplicationName, string lpCommandLine,
        IntPtr lpProcessAttributes, IntPtr lpThreadAttributes, bool bInheritHandles,
        uint dwCreationFlags, IntPtr lpEnvironment, string? lpCurrentDirectory,
        ref STARTUPINFO lpStartupInfo, out PROCESS_INFORMATION lpProcessInformation);

    [DllImport("kernel32.dll")]
    private static extern uint WaitForSingleObject(IntPtr hHandle, uint dwMilliseconds);

    [DllImport("kernel32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool TerminateProcess(IntPtr hProcess, uint uExitCode);

    [DllImport("kernel32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CloseHandle(IntPtr hObject);
}
