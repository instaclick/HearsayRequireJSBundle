<?php

/**
 * Copyright (c) 2011 Hearsay News Products, Inc.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace Hearsay\RequireJSBundle\Assetic\Filter;

use Assetic\Asset\AssetInterface;
use Assetic\Exception\FilterException;
use Assetic\Filter\BaseNodeFilter;

use Hearsay\RequireJSBundle\Exception\RuntimeException;

/**
 * This class represents the r.js filter for Assetic
 *
 * @author Kevin Montag <kevin@hearsay.it>
 * @author Igor Timoshenko <igor.timoshenko@i.ua>
 */
class RJsFilter extends BaseNodeFilter
{
    /**
     * The base URL, named for consistency with the r.js API, note that this is
     * generally actually a filesystem path
     *
     * @var string
     */
    protected $baseUrl;

    /**
     * An array of modules to exclude
     *
     * @var array
     */
    protected $exclude = array();

    /**
     * An array of modules to treat as externals which don't need to be loaded
     *
     * @var array
     */
    protected $external = array();

    /**
     * The absolute path to the node.js
     *
     * @var string
     */
    protected $nodePath;

    /**
     * An array of options
     *
     * @var array
     */
    protected $options = array();

    /**
     * An array of paths
     *
     * @var array
     */
    protected $paths = array();

    /**
     * The absolute path to the r.js
     *
     * @var string
     */
    protected $rPath;

    /**
     * The shim config
     *
     * @var array
     */
    protected $shim = array();

    /**
     * The constructor method
     *
     * @param string $nodePath The absolute path to the node.js
     * @param string $rPath    The absolute path to the r.js
     * @param string $baseUrl  The base URL
     */
    public function __construct($nodePath, $rPath, $baseUrl)
    {
        $this->nodePath = $nodePath;
        $this->rPath    = $rPath;
        $this->baseUrl  = $baseUrl;
    }

    /**
     * {@inheritDoc}
     * @codeCoverageIgnore
     */
    public function filterLoad(AssetInterface $asset)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function filterDump(AssetInterface $asset)
    {
        $pb = $this->createProcessBuilder($this->nodePath
            ? array($this->nodePath, $this->rPath)
            : array($this->rPath)
        );

        // Input and output files
        $input  = tempnam(sys_get_temp_dir(), 'input');
        $output = tempnam(sys_get_temp_dir(), 'output');

        file_put_contents($input, $asset->getContent());

        $buildProfile = $this->makeBuildProfile($input, $output, $asset);

        $pb->add('-o')->add($buildProfile);

        $proc = $pb->getProcess();
        $code = $proc->run();

        unlink($input);

        if ($code !== 0) {
            if (file_exists($output)) {
                unlink($output);
            }

            if (file_exists($buildProfile)) {
                unlink($buildProfile);
            }

            if ($code === 127) {
                throw new RuntimeException(
                    'Path to node executable could not be resolved.'
                );
            }

            throw FilterException::fromProcess($proc)->setInput($asset->getContent());
        }

        if (!file_exists($output)) {
            throw new RuntimeException('Error creating output file.');
        }

        $asset->setContent(file_get_contents($output));

        unlink($output);
        unlink($buildProfile);
    }

    /**
     * Adds the module to exclude
     *
     * @param string $module The module name
     */
    public function addExclude($module)
    {
        $this->exclude[] = $module;
    }

    /**
     * Adds the module to treat as an external that don't need to be loaded
     *
     * @param string $module The module name
     */
    public function addExternal($module)
    {
        $this->external[] = $module;
    }

    /**
     * Adds the option
     *
     * @param string $name  The option name
     * @param mixed  $value The option value
     */
    public function addOption($name, $value)
    {
        $this->options[$name] = $value;
    }

    /**
     * Adds the module path
     *
     * @param string $module The module name
     * @param string $path   The module path
     */
    public function addPath($module, $path)
    {
        $this->paths[$module] = $path;
    }

    /**
     * Sets the shim config
     *
     * @param array $shim The shim config
     */
    public function setShim(array $shim)
    {
        $this->shim = $shim;
    }
    
    /**
     * Returns true if the configuration has defined several output modules, instead of just one
     * 
     * @return type
     */
    public function hasModules()
    {
      return isset($this->options['modules']);
    }
    
    /**
     * Returns the matching module name for the building asset
     * 
     * @param \Assetic\Asset\AssetInterface $asset
     * @return string
     */
    public function getNameForAsset(AssetInterface $asset)
    {
        $path = null;
        $name = null;
      
        $sourceLocation = realpath($asset->getSourceRoot().DIRECTORY_SEPARATOR.$asset->getSourcePath());

        foreach ($this->paths as $key => $value) {
            if (strpos($sourceLocation, realpath($value)) === 0){

                $path = $key;

                break;
            }
        }

        foreach ($this->options['modules'] as $module) {
            $realModuleName = str_replace($path, $this->paths[$path] , $module['name']);

            if (strpos($sourceLocation, $realModuleName) === 0) {
                $name = $module['name'];
                break;
            }
        }

        return $name;
    }

    /**
     * Makes the build profile's file
     *
     * @param  string $input  The input file
     * @param  string $output The output file
     * @param  AssetInterface $asset The AssetInterface
     * @return string         Returns the build profile's file name
     */
    protected function makeBuildProfile($input, $output, AssetInterface $asset)
    {
        $buildProfile = tempnam(sys_get_temp_dir(), 'build_profile');

        $name = md5($input);

        // The basic build profile
        $content = (object) array(
            'baseUrl' => $this->baseUrl,
            'paths'   => new \stdClass(),
            'name'    => $name,
            'out'     => $output,
        );

        // @link http://requirejs.org/docs/optimization.html#empty
        foreach ($this->external as $external) {
            $content->paths->$external = 'empty:';
        }

        $content->paths->$name = $input;

        foreach ($this->paths as $path => $location) {
            $content->paths->$path = $location;
        }

        // Duplicate the shim config
        foreach ($this->shim as &$shim) {
            $shim = (object) $shim;
        }

        unset($shim);

        $content->shim    = (object) $this->shim;
        $content->exclude = $this->exclude;

        foreach ($this->options as $option => $value) {
            // @link https://github.com/jrburke/requirejs/wiki/Upgrading-to-RequireJS-2.0#wiki-delayed
            if ($option == 'insertRequire') {
                $value = $name;
            }

            $content->$option = $value;
        }
        
        // If the configuration specifies several output modules, override the name option so it fits the currently building asset, and unset the modules definition from the build config
        if ($this->hasModules()) {
            $content->name = $this->getNameForAsset($asset);
            // Loop over 
            foreach ($this->options['modules'] as $module) {
                if ($module['name'] == $content->name) {
                    foreach ($module as $key => $value) {
                        $content->$key = $value;
                    }
                }
            }
            unset($content->modules);
        }
        
        file_put_contents($buildProfile, '(' . json_encode($content) . ')');

        return $buildProfile;
    }
}
