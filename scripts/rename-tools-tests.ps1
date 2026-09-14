# Script temporaire : renomme les outils pointés -> underscores dans les tests
$ErrorActionPreference = 'Stop'
$root = 'k:\my project\laravel project\FORMADOC'
$files = @(
    'tests\Unit\Services\Chat\ChatToolsServiceTest.php',
    'tests\Unit\Services\Chat\CapabilitiesServiceTest.php',
    'tests\Unit\Services\OpenRouter\OpenRouterMultiTurnCostTest.php',
    'tests\Feature\ChatAttachmentsTest.php'
)
$map = [ordered]@{
    'web.search' = 'web_search'
    'image.generate' = 'image_generate'
    'cover_page.generate' = 'cover_page_generate'
    'structure.correct' = 'structure_correct'
    'document.edit' = 'document_edit'
    'document.create' = 'document_create'
    'document.analyze' = 'document_analyze'
    'document.to_pdf' = 'document_to_pdf'
    'document.to_docx' = 'document_to_docx'
    'document.reconstruct' = 'document_reconstruct'
}
foreach ($f in $files) {
    $p = Join-Path $root $f
    $c = [System.IO.File]::ReadAllText($p)
    foreach ($k in $map.Keys) {
        $c = $c.Replace($k, $map[$k])
    }
    [System.IO.File]::WriteAllText($p, $c, (New-Object System.Text.UTF8Encoding($false)))
    Write-Host "OK $f"
}
Write-Host 'TERMINE'
