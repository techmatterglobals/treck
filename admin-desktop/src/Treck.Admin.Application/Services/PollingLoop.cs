namespace Treck.Admin.Application.Services;

public sealed class PollingLoop
{
    public async Task RunAsync(
        TimeSpan interval,
        Func<CancellationToken, Task> refresh,
        CancellationToken cancellationToken)
    {
        while (!cancellationToken.IsCancellationRequested)
        {
            await refresh(cancellationToken);
            await Task.Delay(interval, cancellationToken);
        }
    }
}
