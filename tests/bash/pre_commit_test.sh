#!/usr/bin/env bash

# Get project root from test file location
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

function set_up() {
  cd "$PROJECT_ROOT" || exit 1
}

function test_pre_commit_script_exists() {
  assert_file_exists "$PROJECT_ROOT/bin/pre-commit"
}

function test_pre_commit_is_executable() {
  local script="$PROJECT_ROOT/bin/pre-commit"
  if [[ -x "$script" ]]; then
    assert_equals "executable" "executable"
  else
    fail "Script is not executable"
  fi
}

function test_pre_commit_outputs_running_message() {
  local output
  output=$("$PROJECT_ROOT/bin/pre-commit" 2>&1 | head -3)

  assert_contains "Running pre-commit checks" "$output"
}

function test_pre_commit_exits_zero_on_success() {
  "$PROJECT_ROOT/bin/pre-commit" >/dev/null 2>&1
  assert_exit_code 0
}

function test_pre_commit_outputs_success_message() {
  local output
  output=$("$PROJECT_ROOT/bin/pre-commit" 2>&1 | tail -3)

  assert_contains "All checks passed" "$output"
}

function test_pre_commit_works_via_symlink() {
  if [[ ! -L "$PROJECT_ROOT/.git/hooks/pre-commit" ]]; then
    skip "Symlink not installed"
    return
  fi

  "$PROJECT_ROOT/.git/hooks/pre-commit" >/dev/null 2>&1
  assert_exit_code 0
}
