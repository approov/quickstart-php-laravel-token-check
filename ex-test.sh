#!/bin/bash
set -o errexit
set -o nounset
set -o pipefail

#######################################
# Approov demo API test harness.
#
# Description:
#   Calls unprotected and protected endpoints of the Approov demo API,
#   validates HTTP status codes and logs complete HTTP exchanges
#   (request + response) to a timestamped log file.
#
# Dependencies:
#   - bash
#   - curl
#   - approov CLI available on PATH and configured
#
# Environment:
#   BASE_URL:
#     Base URL of the API under test.
#     Default: http://localhost:8080
#
#   TOKDIR:
#     Directory where temporary token files are stored.
#     Default: .config
#
#   Tests:
#   0 - Unprotected request (no Approov protection): Access unprotected endpoint.
# 1.1 - Token check (valid token): Valid Approov token.
# 1.2 - Token check (invalid token): Invalid Approov token.
# 2.1 - Single binding (valid token + header): Valid token and correct Authorization header.
# 2.2 - Single binding (missing Authorization header): Valid token, missing Authorization.
# 2.3 - Single binding (incorrect Authorization header): Valid token, wrong Authorization.
# 2.4 - Single binding (invalid token): Invalid token, correct Authorization.
# 3.1 - Double binding (valid token + headers): Valid token, both binding headers.
# 3.2 - Double binding (missing binding headers): Valid token, missing both headers.
# 3.3 - Double binding (incorrect binding headers): Valid token, wrong headers.
# 3.4 - Double binding (invalid token): Invalid token, correct headers.
# 4.1 - token-check (no Approov header): Protected endpoint, no Approov header.
# 4.1 - token-binding (no Approov header): Protected endpoint, no Approov header.
# 4.1 - token-double-binding (no Approov header): Protected endpoint, no Approov header.
# 4.2 - unprotected (valid token only): Unprotected endpoint, valid token only.
# 4.2 - token-check (valid token only): Protected endpoint, valid token only.
# 4.2 - token-binding (valid token only): Protected endpoint, valid token only.
# 4.2 - token-double-binding (valid token only): Protected endpoint, valid token only.
# 4.3 - token-binding (valid token + Authorization): Valid single-binding token and Authorization.
# 4.3 - unprotected (valid token + Authorization): Unprotected endpoint, valid token and Authorization.
# 4.3 - token-check (valid token + Authorization): Protected endpoint, valid token and Authorization.
# 4.3 - token-double-binding (valid token + Authorization): Double-binding endpoint, valid token and Authorization.
# 4.4 - token-double-binding (valid token + two bindings): Double-binding endpoint, valid token and both headers.
# 4.4 - unprotected (valid token + two bindings): Unprotected endpoint, valid token and both headers.
# 4.4 - token-check (valid token + two bindings): Protected endpoint, valid token and both headers.
# 4.4 - token-binding (valid token + two bindings): Single-binding endpoint, valid token and both headers.
# 5.1 - Bad token (bad signature): Token with bad signature.
# 5.2 - Bad token (invalid encoding): Token with invalid encoding.
# 5.3 - Bad token (no expiry): Token with no expiry.
# 5.4 - Bad token (no expiry simulated): Simulated token with no expiry.
# 5.5 - Bad token (expired): Explicitly expired token.
# 5.6 - Missing binding with good full token: Double-binding endpoint, valid token and both headers, but token not bound.
# 5.7 - Missing Authorization with valid binding token: Double-binding endpoint, valid token and Content-Digest only.
# 5.8 - Good full token with correct binding: Double-binding endpoint, valid token and correct headers.
# 5.9 - Correct token but wrong binding headers: Double-binding endpoint, valid token and wrong headers.
#######################################

# Constants
readonly BASE_URL="${BASE_URL:-http://localhost:8080}"
readonly TOKDIR="${TOKDIR:-.config}"
readonly LOGDIR="${TOKDIR}/logs"
readonly LOGFILE="${LOGDIR}/$(date '+%Y-%m-%d_%H-%M-%S').log"

# Globals
# is_approov_disabled:
#   Boolean flag indicating if Approov checks appear disabled
#   based on /approov-state endpoint.
is_approov_disabled=false
success_code=200
failure_code=401

# state_http_code:
#   HTTP status code from /approov-state endpoint.
state_http_code=''

#######################################
# Print error message to STDERR with timestamp.
# Globals:
#   None
# Arguments:
#   All arguments are printed as the error message.
# Outputs:
#   Writes formatted error message to STDERR.
# Returns:
#   0
#######################################
err() {
	echo "[$(date +'%Y-%m-%dT%H:%M:%S%z')]: $*" >&2
}

#######################################
# Ensure a required command exists on PATH.
# Globals:
#   None
# Arguments:
#   command name to check.
# Outputs:
#   Error message to STDERR if command is missing.
# Returns:
#   Exits the script with code 1 if the command is missing.
#######################################
requirement_check() {
	local cmd="$1"

	if ! command -v "${cmd}" >/dev/null 2>&1; then
		err "Missing required command: ${cmd}"
		exit 1
	fi
}

#######################################
# Generate an Approov token into an output file.
# Globals:
#   None
# Arguments:
#   output file path.
#   arguments passed to "approov token".
# Outputs:
#   Captures stdout+stderr from "approov token", takes the last non-empty
#   line as the token, and writes only that line to the output file.
# Returns:
#   0 on success.
#   1 on failure (CLI error or no token produced).
#######################################
gen_token() {
	local outfile="$1"
	shift

	set +o errexit
	local cli_output
	cli_output="$(approov token "$@" 2>&1)"
	local rc=$?
	set -o errexit

	if ((rc != 0)); then
		err "Approov CLI failed: approov token $*"
		printf '%s\n' "${cli_output}" >&2
		return 1
	fi

	# Prints notices before the token, grab the last non-empty line.
	local token
	token="$(printf '%s\n' "${cli_output}" | awk 'NF{last=$0} END{print last}')"
	if [[ -z "${token}" ]]; then
		err "Approov CLI produced no token output"
		return 1
	fi

	printf '%s\n' "${token}" >"${outfile}"
}

#######################################
# Print test result and append full HTTP exchange to a log file.
# Globals:
#   LOGFILE
#   is_approov_disabled
# Arguments:
#   $1 - test name.
#   $2 - expected HTTP status code.
#   $3 - actual HTTP status code.
#   $4 - full HTTP response (headers + body).
# Outputs:
#   Human-readable result to STDOUT.
#   Detailed log entry appended to LOGFILE.
# Returns:
#   0
#######################################
print_test_result() {
	local name="$1"
	local expected="$2"
	local status="$3"
	local resp="$4"

	local result="Failed"
	if [[ "${status}" == "${expected}" ]]; then
		result="Passed"
	fi

	echo "${name}: ${result}  (status: ${status}, expected: ${expected})"

	{
		echo "Test: ${name}"
		echo "Expected status: ${expected}"
		echo "Actual status:   ${status}"
		if [[ "${is_approov_disabled}" == "false" ]]; then
			echo "Approov State: enabled, token checks performed."
		else
			echo "Approov State: disabled, no checks performed."
		fi
		echo
		echo "HTTP exchange:"
		echo "${resp}"
		echo
	} >>"${LOGFILE}" 2>&1
}

#######################################
# Execute a curl call for a test and evaluate the result.
# Globals:
#   None
# Notes:
#   Uses print_test_result, which logs to LOGFILE and reads
#   is_approov_disabled.
# Arguments:
#   test name.
#   expected HTTP status code.
#   arguments passed to curl.
# Outputs:
#   Short result to STDOUT, full HTTP exchange appended to LOGFILE.
# Returns:
#   0 on success, curl's exit code on failure.
#######################################
run_test() {
	# shift after each grab so $1 advances (name -> expected -> rest)
	local name="$1"; shift
	local expected="$1"; shift

	local resp
	local status
	local curl_rc

	# -i: include headers, -s: silent
	set +o errexit
	resp="$(curl -i -s "$@")"
	curl_rc=$?
	set -o errexit

	if ((curl_rc != 0)); then
		err "curl failed for ${name} (rc=${curl_rc})"
		return "${curl_rc}"
	fi

	status="$(
		printf '%s\n' "${resp}" |
			grep -m1 '^HTTP/' |
			awk '{print $2}'
	)"

	print_test_result "${name}" "${expected}" "${status}" "${resp}"
}

main() {
	requirement_check "approov"
	requirement_check "curl"

	mkdir -p "${TOKDIR}" "${LOGDIR}"

	echo "Listing Approov API configuration:"
	approov api -list
	echo

	echo "Approov state check:"
	local state_response
	state_response="$(curl -i -s "${BASE_URL}/approov-state")"
	state_http_code="$(
		printf '%s\n' "${state_response}" |
			grep -m1 '^HTTP/' |
			awk '{print $2}'
	)"

	if [[ "${state_http_code}" != "200" || -z "${state_http_code}" ]]; then
		err "Failed to get Approov state from ${BASE_URL}/approov-state (status=${state_http_code:-unknown})"
		exit 1
	fi

	if grep -q '"approovEnabled":true' <<<"${state_response}"; then
		echo " Approov service: ENABLED"
		is_approov_disabled=false
	else
		echo " Approov service: DISABLED"
		is_approov_disabled=true
		failure_code=200
	fi
	echo

	# 0) Unprotected endpoint.
	run_test \
		"0 - Unprotected request - no approov protection" \
		"${success_code}" \
		"${BASE_URL}/unprotected"

	# 1) Token check.
	gen_token \
		"${TOKDIR}/approov_token_valid" \
		-genExample \
		example.com

	# 1.1) Valid Token.
	run_test \
		"1.1 - Token check - valid token" \
		"${success_code}" \
		-H "approov-token: $(<"${TOKDIR}/approov_token_valid")" \
		"${BASE_URL}/token-check"

	# 1.2) Invalid Token.
	gen_token \
		"${TOKDIR}/approov_token_invalid" \
		-genExample \
		example.com \
		-type invalid || true

	run_test \
		"1.2 - Token check - invalid token" \
		"${failure_code}" \
		-H "approov-token: $(<"${TOKDIR}/approov_token_invalid")" \
		"${BASE_URL}/token-check"

	# 2) Token Binding ["Authorization"].
	local AUTH_VAL="ExampleAuthToken=="
	export HASH_INPUT="${AUTH_VAL}"

	gen_token \
		"${TOKDIR}/approov_token_bind_auth_valid" \
		-setDataHashInToken "${HASH_INPUT}" \
		-genExample \
		example.com

	# 2.1) Valid Token.
	run_test \
		"2.1 - Single Binding - valid token and header" \
		"${success_code}" \
		-H "Authorization: ${AUTH_VAL}" \
		-H "approov-token: $(<"${TOKDIR}/approov_token_bind_auth_valid")" \
		"${BASE_URL}/token-binding"

	# 2.2) Missing Header.
	run_test \
		"2.2 - Single Binding - missing Authorization header" \
		"${failure_code}" \
		-H "approov-token: $(<"${TOKDIR}/approov_token_bind_auth_valid")" \
		"${BASE_URL}/token-binding"

	# 2.3) Incorrect Header.
	run_test \
		"2.3 - Single Binding - incorrect Authorization header" \
		"${failure_code}" \
		-H "Authorization: BadAuthToken==" \
		-H "approov-token: $(<"${TOKDIR}/approov_token_bind_auth_valid")" \
		"${BASE_URL}/token-binding"

	# 2.4) Invalid Token.
	gen_token \
		"${TOKDIR}/approov_token_bind_auth_invalid" \
		-setDataHashInToken "${HASH_INPUT}" \
		-genExample \
		example.com \
		-type invalid || true

	run_test \
		"2.4 - Single Binding - invalid token" \
		"${failure_code}" \
		-H "Authorization: ${AUTH_VAL}" \
		-H "approov-token: $(<"${TOKDIR}/approov_token_bind_auth_invalid")" \
		"${BASE_URL}/token-binding"

	# 3) Token Binding ["Authorization", "Content-Digest"].
	local AUTH_VAL2="ExampleAuthToken=="
	local CD_VAL="ContentDigest=="
	export HASH_INPUT="${AUTH_VAL2}${CD_VAL}"

	gen_token \
		"${TOKDIR}/approov_token_bind_auth_cd_valid" \
		-setDataHashInToken "${HASH_INPUT}" \
		-genExample \
		example.com

	# 3.1) Valid.
	run_test \
		"3.1 - Double Binding - valid token and headers" \
		"${success_code}" \
		-H "Authorization: ${AUTH_VAL2}" \
		-H "Content-Digest: ${CD_VAL}" \
		-H "approov-token: $(<"${TOKDIR}/approov_token_bind_auth_cd_valid")" \
		"${BASE_URL}/token-double-binding"

	# 3.2) Missing headers.
	run_test \
		"3.2 - Double Binding - missing binding headers" \
		"${failure_code}" \
		-H "approov-token: $(<"${TOKDIR}/approov_token_bind_auth_cd_valid")" \
		"${BASE_URL}/token-double-binding"

	# 3.3) Incorrect headers.
	run_test \
		"3.3 - Double Binding - incorrect binding headers" \
		"${failure_code}" \
		-H "Authorization: BadAuthToken==" \
		-H "Content-Digest: BadContentDigest==" \
		-H "approov-token: $(<"${TOKDIR}/approov_token_bind_auth_cd_valid")" \
		"${BASE_URL}/token-double-binding"

	# 3.4) Invalid token.
	gen_token \
		"${TOKDIR}/approov_token_bind_auth_cd_invalid" \
		-setDataHashInToken "${HASH_INPUT}" \
		-genExample \
		example.com \
		-type invalid || true

	run_test \
		"3.4 - Double Binding - invalid token" \
		"${failure_code}" \
		-H "Authorization: ${AUTH_VAL2}" \
		-H "Content-Digest: ${CD_VAL}" \
		-H "approov-token: $(<"${TOKDIR}/approov_token_bind_auth_cd_invalid")" \
		"${BASE_URL}/token-double-binding"

	# 4) Extreme tests: headers and tokens presence/absence.

	# 4.1) Protected endpoints without any Approov header.
	run_test \
		"4.1 - token-check (no Approov header)" \
		"${failure_code}" \
		"${BASE_URL}/token-check"

	run_test \
		"4.1 - token-binding (no Approov header)" \
		"${failure_code}" \
		"${BASE_URL}/token-binding"

	run_test \
		"4.1 - token-double-binding (no Approov header)" \
		"${failure_code}" \
		"${BASE_URL}/token-double-binding"

	# 4.2) Valid token only (no binding headers).
	if [[ -f "${TOKDIR}/approov_token_valid" ]]; then
		run_test \
			"4.2 - unprotected (valid token only)" \
			"${success_code}" \
			-H "approov-token: $(<"${TOKDIR}/approov_token_valid")" \
			"${BASE_URL}/unprotected"

		run_test \
			"4.2 - token-check (valid token only)" \
			"${success_code}" \
			-H "approov-token: $(<"${TOKDIR}/approov_token_valid")" \
			"${BASE_URL}/token-check"

		run_test \
			"4.2 - token-binding (valid token only)" \
			"${failure_code}" \
			-H "approov-token: $(<"${TOKDIR}/approov_token_valid")" \
			"${BASE_URL}/token-binding"

		run_test \
			"4.2 - token-double-binding (valid token only)" \
			"${failure_code}" \
			-H "approov-token: $(<"${TOKDIR}/approov_token_valid")" \
			"${BASE_URL}/token-double-binding"
	fi

	# 4.3) Valid single-binding token + Authorization header.
	if [[ -f "${TOKDIR}/approov_token_bind_auth_valid" ]]; then
		run_test \
			"4.3 - token-binding (valid token + Authorization)" \
			"${success_code}" \
			-H "Authorization: ${AUTH_VAL}" \
			-H "approov-token: $(<"${TOKDIR}/approov_token_bind_auth_valid")" \
			"${BASE_URL}/token-binding"

		run_test \
			"4.3 - unprotected (valid token + Authorization)" \
			"${success_code}" \
			-H "Authorization: ${AUTH_VAL}" \
			-H "approov-token: $(<"${TOKDIR}/approov_token_bind_auth_valid")" \
			"${BASE_URL}/unprotected"

		run_test \
			"4.3 - token-check (valid token + Authorization)" \
			"${success_code}" \
			-H "Authorization: ${AUTH_VAL}" \
			-H "approov-token: $(<"${TOKDIR}/approov_token_bind_auth_valid")" \
			"${BASE_URL}/token-check"

		run_test \
			"4.3 - token-double-binding (valid token + Authorization)" \
			"${failure_code}" \
			-H "Authorization: ${AUTH_VAL}" \
			-H "approov-token: $(<"${TOKDIR}/approov_token_bind_auth_valid")" \
			"${BASE_URL}/token-double-binding"
	fi

	# 4.4) Valid double-binding token + two binding headers.
	if [[ -f "${TOKDIR}/approov_token_bind_auth_cd_valid" ]]; then
		run_test \
			"4.4 - token-double-binding (valid token + two bindings)" \
			"${success_code}" \
			-H "Authorization: ${AUTH_VAL2}" \
			-H "Content-Digest: ${CD_VAL}" \
			-H "approov-token: $(<"${TOKDIR}/approov_token_bind_auth_cd_valid")" \
			"${BASE_URL}/token-double-binding"

		run_test \
			"4.4 - unprotected (valid token + two bindings)" \
			"${success_code}" \
			-H "Authorization: ${AUTH_VAL2}" \
			-H "Content-Digest: ${CD_VAL}" \
			-H "approov-token: $(<"${TOKDIR}/approov_token_bind_auth_cd_valid")" \
			"${BASE_URL}/unprotected"

		run_test \
			"4.4 - token-check (valid token + two bindings)" \
			"${success_code}" \
			-H "Authorization: ${AUTH_VAL2}" \
			-H "Content-Digest: ${CD_VAL}" \
			-H "approov-token: $(<"${TOKDIR}/approov_token_bind_auth_cd_valid")" \
			"${BASE_URL}/token-check"

		run_test \
			"4.4 - token-binding (valid token + two bindings)" \
			"${failure_code}" \
			-H "Authorization: ${AUTH_VAL2}" \
			-H "Content-Digest: ${CD_VAL}" \
			-H "approov-token: $(<"${TOKDIR}/approov_token_bind_auth_cd_valid")" \
			"${BASE_URL}/token-binding"
	fi

	# 5) Extreme tests: bad tokens and binding mismatches.

	# 5.1) Bad token with bad signature (modified third segment).
	if [[ -f "${TOKDIR}/approov_token_valid" ]]; then
		local good_tok
		local bad_sig_tok

		good_tok="$(<"${TOKDIR}/approov_token_valid")"
		bad_sig_tok="$(
			awk -F. \
				'{printf "%s.%s.%s", $1, $2, "bogussignature"}' \
				<<<"${good_tok}"
		)"

		run_test \
			"5.1 - Bad token (bad signature)" \
			"${failure_code}" \
			-H "approov-token: ${bad_sig_tok}" \
			"${BASE_URL}/token-check"
	fi

	# 5.2) Bad token with invalid encoding.
	local bad_token_invalid_encoding
	bad_token_invalid_encoding="eyJ0eXAiOiJKV1QiLCJlbmMiOiJBMjU2R0NNIn0.""\
eyJleHAiOjE5OTk5OTk5OTksImRpZCI6IkV4YW1wbGVBcHByb292VG9rZW5ESUQ9PSJ9.""\
NwqfsaOUBfXaf8KxRZovYCy0c6hqy29g88z1LIgzuQY"

	run_test \
		"5.2 - Bad token (invalid encoding)" \
		"${failure_code}" \
		-H "approov-token: ${bad_token_invalid_encoding}" \
		"${BASE_URL}/token-check"

	# 5.3) / 5.4) Bad token with no expiry (real or simulated).
	local exp_noexp
	exp_noexp="${failure_code}"

	if [[ -n "${BAD_TOKEN_NO_EXPIRY:-}" ]]; then
		run_test \
			"5.3 - Bad token (no expiry)" \
			"${exp_noexp}" \
			-H "approov-token: ${BAD_TOKEN_NO_EXPIRY}" \
			"${BASE_URL}/token-check"
	elif [[ -f "${TOKDIR}/approov_token_valid" ]]; then
		local hdr_payload
		local noexp_tok

		hdr_payload="$(cut -d. -f1-2 <"${TOKDIR}/approov_token_valid")"
		noexp_tok="${hdr_payload}.nosig"

		run_test \
			"5.4 - Bad token (no expiry simulated)" \
			"${exp_noexp}" \
			-H "approov-token: ${noexp_tok}" \
			"${BASE_URL}/token-check"
	fi

	# 5.5) Explicit expired token.
	local bad_token_expired
	bad_token_expired="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.""\
eyJhdWQiOiIiLCJleHAiOjE3NjIzNTg3OTcsImlwIjoiMS4yLjMuNCIsImRpZCI6IkV4YW1w""\
bGVBcHByb292VG9rZW5ESUQ9PSJ9.vQZqzUAOkjdqDRWMjUYQFwkwFd9sRn1UjXyZCIymNcE"

	run_test \
		"5.5 - Bad token (expired)" \
		"${failure_code}" \
		-H "approov-token: ${bad_token_expired}" \
		"${BASE_URL}/token-check"

	# 5.6) Missing binding but valid full token for double-binding endpoint.
	if [[ -f "${TOKDIR}/approov_token_valid" ]]; then
		run_test \
			"5.6 - Missing binding with good full token" \
			"${failure_code}" \
			-H "Authorization: ${AUTH_VAL}" \
			-H "Content-Digest: ${CD_VAL}" \
			-H "approov-token: $(<"${TOKDIR}/approov_token_valid")" \
			"${BASE_URL}/token-double-binding"
	fi

	# 5.7) / 5.8) / 5.9) Various binding issues with double-binding token.
	if [[ -f "${TOKDIR}/approov_token_bind_auth_cd_valid" ]]; then
		# 5.7) Missing Authorization with valid binding token.
		run_test \
			"5.7 - Missing Authorization with valid binding token" \
			"${failure_code}" \
			-H "Content-Digest: ${CD_VAL}" \
			-H "approov-token: $(<"${TOKDIR}/approov_token_bind_auth_cd_valid")" \
			"${BASE_URL}/token-double-binding"

		# 5.8) Good full token with binding.
		run_test \
			"5.8 - Good full token with correct binding" \
			"${success_code}" \
			-H "Authorization: ${AUTH_VAL}" \
			-H "Content-Digest: ${CD_VAL}" \
			-H "approov-token: $(<"${TOKDIR}/approov_token_bind_auth_cd_valid")" \
			"${BASE_URL}/token-double-binding"

		# 5.9) Correctly signed token but wrong binding headers.
		run_test \
			"5.9 - Correct token but wrong binding headers" \
			"${failure_code}" \
			-H "Authorization: WrongAuth==" \
			-H "Content-Digest: WrongDigest==" \
			-H "approov-token: $(<"${TOKDIR}/approov_token_bind_auth_cd_valid")" \
			"${BASE_URL}/token-double-binding"
	fi

	echo
	echo "Full request and response details are saved in:"
	echo "  ${LOGFILE}"
}

main "$@"
