[CmdletBinding()]
param(
    [ValidateSet('all', '6.0', '7.0')]
    [string] $Target = 'all'
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$projectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$distRoot = Join-Path $projectRoot 'dist'
$targets = if ($Target -eq 'all') { @('6.0', '7.0') } else { @($Target) }
$runtimeDirectories = @('actions', 'assets', 'views')

New-Item -ItemType Directory -Force -Path $distRoot | Out-Null

foreach ($zabbixVersion in $targets) {
    $platformRoot = Join-Path $projectRoot ('platforms\zabbix-' + $zabbixVersion)
    $manifestPath = Join-Path $platformRoot 'manifest.json'
    if (!(Test-Path -LiteralPath $manifestPath)) {
        throw "Platform manifest not found: $manifestPath"
    }

    $manifest = Get-Content -Raw -LiteralPath $manifestPath | ConvertFrom-Json
    $temporaryRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('zabbix-governance-build-' + [guid]::NewGuid().ToString('N'))
    $moduleRoot = Join-Path $temporaryRoot 'Governance'
    New-Item -ItemType Directory -Force -Path $moduleRoot | Out-Null

    try {
        Get-ChildItem -LiteralPath $projectRoot -File -Filter '*.php' | ForEach-Object {
            Copy-Item -LiteralPath $_.FullName -Destination $moduleRoot
        }
        Copy-Item -LiteralPath (Join-Path $projectRoot 'README.md') -Destination $moduleRoot

        foreach ($directory in $runtimeDirectories) {
            Copy-Item -LiteralPath (Join-Path $projectRoot $directory) -Destination $moduleRoot -Recurse
        }

        Copy-Item -LiteralPath (Join-Path $platformRoot 'manifest.json') -Destination $moduleRoot
        Copy-Item -LiteralPath (Join-Path $platformRoot 'Module.php') -Destination $moduleRoot

        $archiveName = 'zabbix-module-governance-zabbix-' + $zabbixVersion + '-' + $manifest.version + '.zip'
        $archivePath = Join-Path $distRoot $archiveName
        if (Test-Path -LiteralPath $archivePath) {
            Remove-Item -LiteralPath $archivePath -Force
        }
        $archive = [System.IO.Compression.ZipFile]::Open(
            $archivePath,
            [System.IO.Compression.ZipArchiveMode]::Create
        )
        try {
            Get-ChildItem -LiteralPath $moduleRoot -Recurse -File | Sort-Object FullName | ForEach-Object {
                $entryName = $_.FullName.Substring($temporaryRoot.Length + 1).Replace('\', '/')
                [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                    $archive,
                    $_.FullName,
                    $entryName,
                    [System.IO.Compression.CompressionLevel]::Optimal
                ) | Out-Null
            }
        }
        finally {
            $archive.Dispose()
        }
        Write-Output $archivePath
    }
    finally {
        $resolvedTemporaryRoot = [System.IO.Path]::GetFullPath($temporaryRoot)
        $systemTemporaryRoot = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
        $isInsideTemporaryRoot = $resolvedTemporaryRoot.StartsWith(
            $systemTemporaryRoot,
            [System.StringComparison]::OrdinalIgnoreCase
        )
        $hasExpectedName = [System.IO.Path]::GetFileName($resolvedTemporaryRoot).StartsWith(
            'zabbix-governance-build-'
        )
        if ($isInsideTemporaryRoot -and $hasExpectedName) {
            Remove-Item -LiteralPath $resolvedTemporaryRoot -Recurse -Force -ErrorAction SilentlyContinue
        }
    }
}
