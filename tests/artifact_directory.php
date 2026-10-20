<?php
declare(strict_types=1);

// Test output never belongs to the repository or a historical deployment tree.
$testProjectRoot = realpath(dirname(__DIR__));
$testArtifactSetting = getenv('RADIO_STAMP_TEST_ARTIFACT_DIR');
if (!is_string($testArtifactSetting) || trim($testArtifactSetting) === '') {
    $testArtifactSetting = rtrim(sys_get_temp_dir(), '/\\') . '/radio-stamp-tests-' . bin2hex(random_bytes(8));
}
if (!preg_match('~\A(?:[A-Za-z]:[/\\\\]|/(?!/))~D', $testArtifactSetting)
    || is_link($testArtifactSetting)
    || (!is_dir($testArtifactSetting) && !mkdir($testArtifactSetting, 0700, true))) {
    throw new RuntimeException('test_artifact_directory_unavailable');
}
$testArtifactRoot = realpath($testArtifactSetting);
if ($testArtifactRoot === false || $testProjectRoot === false) {
    throw new RuntimeException('test_artifact_directory_unavailable');
}
$testProjectBoundary = str_replace('\\', '/', $testProjectRoot) . '/';
$testArtifactBoundary = str_replace('\\', '/', $testArtifactRoot) . '/';
$testInsideProject = PHP_OS_FAMILY === 'Windows'
    ? str_starts_with(strtolower($testArtifactBoundary), strtolower($testProjectBoundary))
    : str_starts_with($testArtifactBoundary, $testProjectBoundary);
if ($testInsideProject) {
    throw new RuntimeException('test_artifact_directory_must_be_outside_repository');
}
putenv('RADIO_STAMP_TEST_ARTIFACT_DIR=' . $testArtifactRoot);
unset($testProjectRoot, $testArtifactSetting, $testArtifactRoot, $testProjectBoundary, $testArtifactBoundary, $testInsideProject);
