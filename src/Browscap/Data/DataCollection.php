<?php

namespace Browscap\Data;

use Psr\Log\LoggerInterface;

/**
 * Class DataCollection
 *
 * @package Browscap\Generator
 */
class DataCollection
{
    /**
     * @var array
     */
    private $platforms = array();

    /**
     * @var array
     */
    private $engines = array();

    /**
     * @var \Browscap\Data\Division[]
     */
    private $divisions = array();

    /**
     * @var array
     */
    private $defaultProperties = array();

    /**
     * @var array
     */
    private $defaultBrowser = array();

    /**
     * @var boolean
     */
    private $divisionsHaveBeenSorted = false;

    /**
     * @var string
     */
    private $version;

    /**
     * @var \DateTime
     */
    private $generationDate;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger = null;

    /** @var array  */
    private $allDivision = array();

    /**
     * Create a new data collection for the specified version
     *
     * @param string $version
     */
    public function __construct($version)
    {
        $this->version        = $version;
        $this->generationDate = new \DateTime();
    }

    /**
     * @param \Psr\Log\LoggerInterface $logger
     *
     * @return \Browscap\Data\DataCollection
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Load a platforms.json file and parse it into the platforms data array
     *
     * @param string $src Name of the file
     *
     * @return \Browscap\Data\DataCollection
     * @throws \RuntimeException if the file does not exist or has invalid JSON
     */
    public function addPlatformsFile($src)
    {
        $json = $this->loadFile($src);

        $this->platforms = $json['platforms'];

        $this->divisionsHaveBeenSorted = false;

        return $this;
    }

    /**
     * Load a engines.json file and parse it into the platforms data array
     *
     * @param string $src Name of the file
     *
     * @return \Browscap\Data\DataCollection
     * @throws \RuntimeException if the file does not exist or has invalid JSON
     */
    public function addEnginesFile($src)
    {
        $json = $this->loadFile($src);

        $this->engines = $json['engines'];

        $this->divisionsHaveBeenSorted = false;

        return $this;
    }

    /**
     * Load a JSON file, parse it's JSON and add it to our divisions list
     *
     * @param string $src Name of the file
     *
     * @return \Browscap\Data\DataCollection
     * @throws \RuntimeException If the file does not exist or has invalid JSON
     * @throws \UnexpectedValueException If required attibutes are missing in the division
     */
    public function addSourceFile($src)
    {
        $divisionData = $this->loadFile($src);

        if (empty($divisionData['division'])) {
            throw new \UnexpectedValueException('required attibute "division" is missing');
        }

        if (empty($divisionData['sortIndex'])) {
            throw new \UnexpectedValueException('required attibute "sortIndex" is missing');
        }

        $division = new Division();
        $division
            ->setName($divisionData['division'])
            ->setSortIndex((int) $divisionData['sortIndex'])
        ;

        if (isset($divisionData['lite'])) {
            $division->setLite((boolean) $divisionData['lite']);
        }

        if (isset($divisionData['versions']) && is_array($divisionData['versions'])) {
            $division->setVersions($divisionData['versions']);
        }

        if (isset($divisionData['userAgents']) && is_array($divisionData['userAgents'])) {
            foreach ($divisionData['userAgents'] as $useragent) {
                if (in_array($useragent['userAgent'], $this->allDivision)) {
                    throw new \UnexpectedValueException('Division "' . $useragent['userAgent'] . '" is defined twice');
                }

                if (!isset($useragent['properties']) || !is_array($useragent['properties'])) {
                    throw new \UnexpectedValueException(
                        'the properties entry has to be an array for key "' . $useragent['userAgent'] . '"'
                    );
                }

                if (!isset($useragent['properties']['Parent'])) {
                    throw new \UnexpectedValueException(
                        'the "parent" property is missing for key "' . $useragent['userAgent'] . '"'
                    );
                }

                $this->checkPlatformData(
                    $useragent['properties'],
                    'the properties array contains platform data for key "' . $useragent['userAgent']
                    . '", please use the "platform" keyword'
                );

                $this->checkEngineData(
                    $useragent['properties'],
                    'the properties array contains engine data for key "' . $useragent['userAgent']
                    . '", please use the "engine" keyword'
                );

                $this->allDivision[] = $useragent['userAgent'];

                if (isset($useragent['children']) && is_array($useragent['children'])) {
                    if (isset($useragent['children']['match'])) {
                        throw new \UnexpectedValueException(
                            'the children property has to be an array of arrays for key "'
                            . $useragent['userAgent'] . '"'
                        );
                    }

                    foreach ($useragent['children'] as $child) {
                        if (!is_array($child)) {
                            throw new \UnexpectedValueException(
                                'each entry of the children property has to be an array for key "'
                                . $useragent['userAgent'] . '"'
                            );
                        }

                        if (!isset($child['match'])) {
                            throw new \UnexpectedValueException(
                                'each entry of the children property requires an "match" entry for key "'
                                . $useragent['userAgent'] . '"'
                            );
                        }

                        if (isset($child['properties'])) {
                            if (!is_array($child['properties'])) {
                                throw new \UnexpectedValueException(
                                    'the properties entry has to be an array for key "' . $child['match'] . '"'
                                );
                            }

                            if (isset($child['properties']['Parent'])) {
                                throw new \UnexpectedValueException(
                                    'the Parent property must not set inside the children array for key "'
                                    . $child['match'] . '"'
                                );
                            }

                            $this->checkPlatformData(
                                $child['properties'],
                                'the properties array contains platform data for key "' . $child['match']
                                . '", please use the "platforms" keyword'
                            );

                            $this->checkEngineData(
                                $child['properties'],
                                'the properties array contains engine data for key "' . $child['match']
                                . '", please use the "engine" keyword'
                            );
                        }

                        //
                    }
                }
            }

            $division->setUserAgents($divisionData['userAgents']);
        }

        $this->divisions[] = $division;

        $this->divisionsHaveBeenSorted = false;

        return $this;
    }

    /**
     * Load a engines.json file and parse it into the platforms data array
     *
     * @param string $src Name of the file
     *
     * @return \Browscap\Data\DataCollection
     * @throws \RuntimeException if the file does not exist or has invalid JSON
     */
    public function addDefaultProperties($src)
    {
        $this->defaultProperties = $this->loadFile($src);

        $this->divisionsHaveBeenSorted = false;

        return $this;
    }

    /**
     * Load a engines.json file and parse it into the platforms data array
     *
     * @param string $src Name of the file
     *
     * @return \Browscap\Data\DataCollection
     * @throws \RuntimeException if the file does not exist or has invalid JSON
     */
    public function addDefaultBrowser($src)
    {
        $this->defaultBrowser = $this->loadFile($src);

        $this->divisionsHaveBeenSorted = false;

        return $this;
    }

    /**
     * @param string $src
     *
     * @return array
     * @throws \RuntimeException
     */
    private function loadFile($src)
    {
        if (!file_exists($src)) {
            throw new \RuntimeException('File "' . $src . '" does not exist.');
        }

        if (!is_readable($src)) {
            throw new \RuntimeException('File "' . $src . '" is not readable.');
        }

        $fileContent = file_get_contents($src);
        $json        = json_decode($fileContent, true);

        if (is_null($json)) {
            throw new \RuntimeException('File "' . $src . '" had invalid JSON.');
        }

        return $json;
    }

    /**
     * Sort the divisions (if they haven't already been sorted)
     */
    public function sortDivisions()
    {
        if (!$this->divisionsHaveBeenSorted) {
            $sortIndex    = array();
            $sortPosition = array();

            foreach ($this->divisions as $key => $division) {
                /** @var \Browscap\Data\Division $division */
                $sortIndex[$key]    = $division->getSortIndex();
                $sortPosition[$key] = $key;
            }

            array_multisort(
                $sortIndex, SORT_ASC, SORT_NUMERIC,
                $sortPosition, SORT_DESC, SORT_NUMERIC, // if the sortIndex is identical the later added file comes first
                $this->divisions
            );

            $this->divisionsHaveBeenSorted = true;
        }
    }

    /**
     * Get the divisions array containing UA data
     *
     * @return array
     */
    public function getDivisions()
    {
        $this->sortDivisions();

        return $this->divisions;
    }

    /**
     * Get the array of platform data
     *
     * @return array
     */
    public function getPlatforms()
    {
        return $this->platforms;
    }

    /**
     * Get a single platform data array
     *
     * @param string $platform
     *
     * @throws \OutOfBoundsException
     * @throws \UnexpectedValueException
     * @return array
     */
    public function getPlatform($platform)
    {
        if (!array_key_exists($platform, $this->platforms)) {
            throw new \OutOfBoundsException(
                'Platform "' . $platform . '" does not exist in data, available platforms: '
                . serialize(array_keys($this->platforms))
            );
        }

        /** @var array $platformData */
        $platformData = $this->platforms[$platform];

        if (array_key_exists('inherits', $platformData)) {
            $parentPlatformData = $this->getPlatform($platformData['inherits']);

            if (array_key_exists('properties', $platformData)) {
                $inheritedPlatformProperties = $platformData['properties'];

                foreach ($inheritedPlatformProperties as $name => $value) {
                    if (isset($parentPlatformData['properties'][$name])
                        && $parentPlatformData['properties'][$name] == $value
                    ) {
                        throw new \UnexpectedValueException(
                            'the value for property "' . $name .'" has the same value in the keys "' . $platform
                            . '" and its parent "' . $platformData['inherits'] . '"'
                        );
                    }
                }

                $platformData['properties'] = array_merge(
                    $parentPlatformData['properties'],
                    $inheritedPlatformProperties
                );
            } else {
                $platformData['properties'] = $parentPlatformData['properties'];
            }

            unset($platformData['inherits']);
        }

        return $platformData;
    }

    /**
     * Get the array of engine data
     *
     * @return array
     */
    public function getEngines()
    {
        return $this->engines;
    }

    /**
     * Get a single engine data array
     *
     * @param string $engine
     *
     * @throws \OutOfBoundsException
     * @throws \UnexpectedValueException
     * @return array
     */
    public function getEngine($engine)
    {
        if (!array_key_exists($engine, $this->engines)) {
            throw new \OutOfBoundsException(
                'Rendering Engine "' . $engine . '" does not exist in data, available engines: '
                . serialize(array_keys($this->engines))
            );
        }

        /** @var array $engineData */
        $engineData = $this->engines[$engine];

        if (array_key_exists('inherits', $engineData)) {
            $parentEngineData = $this->getEngine($engineData['inherits']);

            if (array_key_exists('properties', $engineData)) {
                $inheritedEngineProperties = $engineData['properties'];

                foreach ($inheritedEngineProperties as $name => $value) {
                    if (isset($parentEngineData['properties'][$name])
                        && $parentEngineData['properties'][$name] == $value
                    ) {
                        throw new \UnexpectedValueException(
                            'the value for property "' . $name .'" has the same value in the keys "' . $engine
                            . '" and its parent "' . $engineData['inherits'] . '"'
                        );
                    }
                }

                $engineData['properties'] = array_merge(
                    $parentEngineData['properties'],
                    $inheritedEngineProperties
                );
            } else {
                $engineData['properties'] = $parentEngineData['properties'];
            }

            unset($engineData['inherits']);
        }

        return $engineData;
    }

    /**
     * Get the version string identifier
     *
     * @return string
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * Get the generation DateTime object
     *
     * @return \DateTime
     */
    public function getGenerationDate()
    {
        return $this->generationDate;
    }

    /**
     * checks if platform properties are set inside a properties array
     *
     * @param array  $properties
     * @param string $message
     *
     * @throws \LogicException
     */
    private function checkPlatformData(array $properties, $message)
    {
        if (array_key_exists('Platform', $properties)
            || array_key_exists('Platform_Description', $properties)
            || array_key_exists('Platform_Maker', $properties)
            || array_key_exists('Platform_Bits', $properties)
            || array_key_exists('Platform_Version', $properties)
        ) {
            throw new \LogicException($message);
        }
    }

    /**
     * checks if platform properties are set inside a properties array
     *
     * @param array  $properties
     * @param string $message
     *
     * @throws \LogicException
     */
    private function checkEngineData(array $properties, $message)
    {
        if (array_key_exists('RenderingEngine_Name', $properties)
            || array_key_exists('RenderingEngine_Version', $properties)
            || array_key_exists('RenderingEngine_Description', $properties)
            || array_key_exists('RenderingEngine_Maker', $properties)
        ) {
            throw new \LogicException($message);
        }
    }
}
