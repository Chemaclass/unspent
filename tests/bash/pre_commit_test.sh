#!/usr/bin/env bash

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

# Each test runs the hook inside a throwaway repo with a fake `composer` on PATH,
# so it never runs the real suite.
function set_up() {
  REPO="$(mktemp -d)"
  mkdir -p "$REPO/bin" "$REPO/fake-bin"
  cp "$PROJECT_ROOT/bin/pre-commit" "$REPO/bin/pre-commit"
  git -C "$REPO" init -q
  export PATH="$REPO/fake-bin:$PATH"
}

function tear_down() {
  rm -rf "$REPO"
}

function fake_composer() {
  printf '#!/usr/bin/env bash\necho "%s"\nexit %s\n' "$1" "$2" > "$REPO/fake-bin/composer"
  chmod +x "$REPO/fake-bin/composer"
}

function stage() {
  echo "x" > "$REPO/$1"
  git -C "$REPO" add "$1"
}

function test_pre_commit_is_executable() {
  assert_file_exists "$PROJECT_ROOT/bin/pre-commit"
  [[ -x "$PROJECT_ROOT/bin/pre-commit" ]] || fail "bin/pre-commit is not executable"
}

function test_skips_checks_when_no_php_files_are_staged() {
  fake_composer "composer should not run" 1
  stage README.md

  local output
  output=$("$REPO/bin/pre-commit" 2>&1)

  assert_exit_code 0
  assert_contains "No PHP changes staged" "$output"
  assert_not_contains "composer should not run" "$output"
}

function test_hides_tool_output_when_checks_pass() {
  fake_composer "noisy tool output" 0
  stage Foo.php

  local output
  output=$("$REPO/bin/pre-commit" 2>&1)

  assert_exit_code 0
  assert_contains "Pre-commit checks passed" "$output"
  assert_not_contains "noisy tool output" "$output"
}

function test_shows_tool_output_and_fails_when_checks_fail() {
  fake_composer "Tests: 3, Failures: 1" 1
  stage Foo.php

  local output
  output=$("$REPO/bin/pre-commit" 2>&1)

  assert_exit_code 1
  assert_contains "Tests: 3, Failures: 1" "$output"
  assert_contains "Pre-commit checks failed" "$output"
}

function test_runs_checks_when_nothing_is_staged() {
  fake_composer "ran" 0

  local output
  output=$("$REPO/bin/pre-commit" 2>&1)

  assert_exit_code 0
  assert_contains "Pre-commit checks passed" "$output"
}

function test_works_via_symlink() {
  fake_composer "ran" 0
  ln -sf ../../bin/pre-commit "$REPO/.git/hooks/pre-commit"
  stage Foo.php

  "$REPO/.git/hooks/pre-commit" >/dev/null 2>&1

  assert_exit_code 0
}
