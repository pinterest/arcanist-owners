<?php
/**
 * Copyright 2020 Pinterest, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Display ownership information for a list of files.
 */
final class ArcanistOwnersWorkflow extends ArcanistWorkflow {

    public function getWorkflowName() {
        return 'owners';
    }

    public function getCommandSynopses() {
        return phutil_console_format(<<<EOTEXT
      **owners** [__path__ ...]
EOTEXT
      );
    }

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT
          Supports: git, hg
          Display ownership information for a list of files.

          Without __paths__, the files changed in your local working copy will
          be used.
EOTEXT
      );
  }

  public function requiresConduit() {
    return true;
  }

  public function requiresAuthentication() {
    return true;
  }

  public function requiresRepositoryAPI() {
    return true;
  }

  public function getArguments() {
    return array(
      '*' => 'paths',
    );
  }

  public function getSupportedRevisionControlSystems() {
    return array('git', 'hg');
  }

  public function run() {
    $paths = $this->selectPathsForWorkflow(
      $this->getArgument('paths'),
      null,
      ArcanistRepositoryAPI::FLAG_UNTRACKED);

    $base_uri = $this
      ->getWorkingCopy()
      ->getProjectConfig('phabricator.uri');

    // Map each input path to all of its matching packages.
    $bypath = array();
    foreach ($this->queryPackages($paths) as $package) {
      $fields = idx($package, 'fields', array());
      if (!empty($base_uri)) {
        $fields['url'] = (string)id(new PhutilURI($base_uri))
            ->setPath("/owners/package/{$package['id']}/");
      }

      foreach ($this->matchPackagePaths($package, $paths) as $path) {
        $bypath[$path][] = $fields;
      }
    }
    ksort($bypath);

    // The paths are initially sorted alphabetically, but we display them
    // grouped based on their common package. For example, if we have paths
    // "A", "B', "C", and both "A" and "C" are owned by the same packages, the
    // output will look like:
    //
    //    A
    //    C
    //      Package Bar
    //      Package Baz
    //    B
    //      Package Foo
    //
    // Packages within each group are grouped by their ownership strength
    // ("strong" before "weak") and then listed alphabetically.
    while (!empty($bypath)) {
      $packages = reset($bypath);

      foreach ($bypath as $path => $path_packages) {
        if ($path_packages === $packages) {
          echo phutil_console_format("**%s**\n", $path);
          unset($bypath[$path]);
        }
      }

      foreach (isort($packages, 'dominion') as $package) {
        echo phutil_console_format("  [%6s] %s\n",
          $package['dominion']['value'],
          $this->linkify($package['name'], $package['url']));
      }
    }
  }

  private function queryPackages($paths) {
    $result = $this->getConduit()->callMethodSynchronous(
      'owners.search',
      array(
        'constraints' => array(
          'repositories' => array($this->getRepositoryPHID()),
          'paths' => $paths,
          'statuses' => array('active'),
        ),
        'attachments' => array(
          'paths' => true,
        ),
        'order' => 'name'
      ));

    return idx($result, 'data', array());
  }

  private function matchPackagePaths($package, $paths) {
    $included = array();
    $excluded = array();
    foreach ($package['attachments']['paths']['paths'] as $spec) {
      if ($spec['repositoryPHID'] !== $this->getRepositoryPHID()) {
        continue;
      }
      $path = trim($spec['path'], '/');
      if (empty($spec['excluded'])) {
        $included[] = $path;
      } else {
        $excluded[] = $path;
      }
    }

    $matches = array();
    foreach ($paths as $path) {
      if ($this->containsPath($included, $path) &&
          !$this->containsPath($excluded, $path)) {
        $matches[] = $path;
      }
    }

    return $matches;
  }

  private function containsPath($paths, $candidate) {
    foreach ($paths as $path) {
      if (substr($candidate, 0, strlen($path)) === $path) {
        return true;
      }
    }
    return false;
  }

  // https://gist.github.com/egmontkob/eb114294efbcd5adb1944c9f3cb5feda
  private function linkify($text, $url) {
    return (!empty($url)) ? "\e]8;;$url\e\\$text\e]8;;\e\\" : $text;
  }
}
