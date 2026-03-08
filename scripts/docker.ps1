param(
    [ValidateSet('up', 'down', 'logs', 'migrate', 'shell-app', 'shell-worker', 'rebuild')]
    [string]$Action = 'up',
    [string]$Service = '',
    [string]$Context = '',
    [string]$DockerHost = ''
)

$ErrorActionPreference = 'Stop'

function Invoke-Compose {
    param([string[]]$Args)

    $composeArgs = @()
    if ($Context -ne '') {
        $composeArgs += @('--context', $Context)
    }

    if ($DockerHost -ne '') {
        $previousDockerHost = $env:DOCKER_HOST
        $env:DOCKER_HOST = $DockerHost
        try {
            docker @composeArgs compose @Args
        } finally {
            if ($null -ne $previousDockerHost) {
                $env:DOCKER_HOST = $previousDockerHost
            } else {
                Remove-Item Env:DOCKER_HOST -ErrorAction SilentlyContinue
            }
        }
        return
    }

    docker @composeArgs compose @Args
}

switch ($Action) {
    'up' {
        Invoke-Compose -Args @('up', '-d', '--build', 'app', 'worker')
    }
    'rebuild' {
        Invoke-Compose -Args @('build', '--no-cache', 'app', 'worker')
    }
    'down' {
        Invoke-Compose -Args @('down')
    }
    'logs' {
        if ($Service -ne '') {
            Invoke-Compose -Args @('logs', '-f', $Service)
        } else {
            Invoke-Compose -Args @('logs', '-f', 'app', 'worker')
        }
    }
    'migrate' {
        Invoke-Compose -Args @('run', '--rm', 'app', 'php', 'scripts/migrate.php')
    }
    'shell-app' {
        Invoke-Compose -Args @('exec', 'app', 'sh')
    }
    'shell-worker' {
        Invoke-Compose -Args @('exec', 'worker', 'sh')
    }
}
