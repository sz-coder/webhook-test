#!/usr/bin/env php
<?php

/**
 * Github Actions Deploy Script v1.1.0
 *
 * Environment variables:
 *
 * NAPSW_PROJECT => Project name
 * NAPSW_DEPLOY_KEY => Deploy key
 * (NAPSW_GIT_BRANCH) => Optional git branch name override
 */

namespace napsw;

define("napsw\\DEPLOY_SCRIPT_VER", "1.1.0");

function debug() {
	foreach (func_get_args() as $arg) {
		fwrite(STDERR, "$arg\n");
	}
}

function exec_or_die($command) {
	debug("Executing command '$command'");

	exec($command." 2>&1", $output, $return_code);

	if ($return_code !== 0) {
		debug("Command failed with exit code = $return_code");

		foreach ($output as $line) {
			debug("    $line");
		}

		exit($return_code);
	}

	return $output;
}

function git_get_branch() {
	$tmp = getenv("NAPSW_GIT_BRANCH");

	if (strlen($tmp)) {
		$git_branch = [$tmp];
	} else {
		$git_branch = exec_or_die("git rev-parse --abbrev-ref HEAD");
	}

	return trim($git_branch[0]);
}

function git_get_commitHash() {
	return trim(exec_or_die("git rev-parse HEAD")[0]);
}

function get_sha256_hash($file) {
	$hash = hash_file("sha256", $file);

	if (!$hash) {
		debug("Failed to calculate SHA256 checksum of '$file'");
		exit(1);
	}

	return $hash;
}

function get_upload_files() {
	$files = [];
	$entries = scandir(__DIR__."/ci.upload-files");

	if (!$entries) {
		debug("Failed to read ci.upload-files directory.");
		exit(1);
	}

	foreach ($entries as $entry) {
		if (substr($entry, 0, 1) === ".") continue;

		$entry_path = __DIR__."/ci.upload-files/$entry";

		array_push($files, [
			"post_name" => md5($entry_path),
			"file_name" => $entry,
			"path"      => $entry_path,
			"checksum"  => get_sha256_hash($entry_path)
		]);
	}

	return $files;
}

function read_env_var($var_name) {
	$value = getenv($var_name);

	if (!$value || !strlen(trim($value))) {
		debug("Could not read environment variable '$var_name'");
		exit(1);
	}

	return trim($value);
}

function get_deploy_meta_data() {
	$git_branch = git_get_branch();
	$git_HEAD = git_get_commitHash();
	$files = get_upload_files();
	$project = read_env_var("NAPSW_PROJECT");

	return [
		"branch" => $git_branch,
		"HEAD"   => $git_HEAD,
		"files"  => $files,
		"project" => $project
	];
}

function main() {
	$meta = get_deploy_meta_data();

	$http_deploy_url = "https://deploy.nap.software/project/".$meta["project"]."/";
	$http_deploy_post_data = [
		"GIT_BRANCH" => $meta["branch"],
		"GIT_HEAD" => $meta["HEAD"],
		"DEPLOY_KEY" => read_env_var("NAPSW_DEPLOY_KEY"),
		"DEPLOY_SCRIPT_VER" => \napsw\DEPLOY_SCRIPT_VER,
		"DEPLOY_SCRIPT_HASH" => get_sha256_hash(__FILE__),
		"UPLOAD_FILES" => []
	];

	foreach ($meta["files"] as $file) {
		$http_deploy_post_data[$file["post_name"]] = curl_file_create(
			$file["path"]
		);

		foreach ($file as $key => $value) {
			if (!in_array($key, ["file_name", "checksum"])) {
				continue;
			}

			$http_deploy_post_data[$file["post_name"]."_$key"] = $value;
		}

		array_push(
			$http_deploy_post_data["UPLOAD_FILES"],
			$file["post_name"]
		);
	}

	$ch = curl_init();

	curl_setopt($ch, CURLOPT_URL, $http_deploy_url);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $http_deploy_post_data);

	curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

	$http_response_body  = curl_exec($ch);
	$http_response_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

	$curl_error_msg = "";

	if (curl_errno($ch)) {
		$curl_error_msg = curl_error($ch);
	}

	$was_successfull = false;

	if (trim($http_response_body) === "ok" && $http_response_code === 200) {
		$was_successfull = true;
	}

	if (!$was_successfull) {
		debug("cURL request $http_deploy_url failed:\n");
		debug("curl_error_msg is '$curl_error_msg'");
		debug("http_response_code is $http_response_code");
		debug("http_response_body is '$http_response_body'");
		debug("http_deploy_post_data is");

		$http_deploy_post_data["DEPLOY_KEY"] = "********";
		unset($http_deploy_post_data["DEPLOY_KEY"]);

		fflush(STDERR);
		print_r($http_deploy_post_data);

		exit(1);
	}

	curl_close($ch);
}

main();
