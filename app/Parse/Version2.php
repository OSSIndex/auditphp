<?php

namespace App\Parse;

use vierbergenlars\SemVer\version;

class Version2
{
    protected $version = '';

    /** @var int Major release number */
    protected $major;

    /** @var int Minor release number */
    protected $minor;

    /** @var int Patch release number */
    protected $patch;

    /** @var string|null Pre-release value */
    protected $preRelease;

    /** @var string Build release value */
    protected $build;

    /**
     * Class constructor, runs on object creation.
     *
     * @param string $version Version string
     */
    public function __construct($version = '0.1.0')
    {
        $this->setVersion($version);
    }

    /**
     * Magic get method; privied access to version properties.
     *
     * @param string $property Version property
     *
     * @return mixed Version property value
     */
    public function __get($property)
    {
        return $this->$property;
    }

    /**
     * Set (override) the entire version value.
     *
     * @param string $version Version string
     *
     * @return Version This Version object
     */
    public function setVersion($version)
    {
        $this->version = new version($version);

        $this->major = $this->version->getMajor();
        $this->minor = $this->version->getMinor();
        $this->patch = $this->version->getPatch();
        $this->build = $this->version->getBuild();

        return $this;
    }

    /**
     * Magic toString method; allows object interaction as if it were a string.
     *
     * @param string $prefix Prefix the version string with a custom string
     *                       (default: 'v')
     *
     * @return string Current version string
     */
    public function __toString()
    {
        return $this->toString(null);
    }

    /**
     * Get the current version value as a string.
     *
     * @return string Current version string
     */
    private function toString()
    {
        return $this->version->getVersion();
    }

    public function decrement()
    {
        if ($this->major > 0)
        {
            $this->decrementMajor();
        }
        else if ($this->minor > 0)
        {
            $this->decrementMinor();

        }
        else if ($this->patch > 0)
        {
            $this->decrementPatch();
        }
        else
        {
            throw new InvalidVersionException("The current version is 0.0.0");
        }
        return $this;
    }

    public function increment()
    {
        $this->incrementPatch();
        return $this;
    }

    /**
     * Increment the major version value by one.
     *
     * @return Version This Version object
     */
    public function incrementMajor()
    {
        $this->setMajor($this->major + 1);

        return $this;
    }

    public function decrementMajor()
    {
        if ($this->major > 0)
        {
            $this->setMajor($this->major - 1);
        }
        return $this;
    }

    /**
     * Set the major version to a custom value.
     *
     * @param int $value Positive integer value
     *
     * @return Version This Version object
     */
    public function setMajor($value)
    {
        $this->major = $value;
        $this->minor = 0;
        $this->patch = 0;
        $this->preRelease = null;

        return $this;
    }

    /**
     * Increment the minor version value by one.
     *
     * @return Version This Version object
     */
    public function incrementMinor()
    {
        $this->setMinor($this->minor + 1);

        return $this;
    }

    public function decrementMinor()
    {
        if ($this->minor > 0)
        {
            $this->setMinor($this->minor - 1);
        }
        return $this;
    }

    /**
     * Set the minor version to a custom value.
     *
     * @param int $value Positive integer value
     *
     * @return Version This Version object
     */
    public function setMinor($value)
    {
        $this->minor = $value;
        $this->patch = 0;
        $this->preRelease = null;

        return $this;
    }

    /**
     * Increment the patch version value by one.
     *
     * @return Version This Version object
     */
    public function incrementPatch()
    {
        $this->setPatch($this->patch + 1);

        return $this;
    }

    public function decrementPatch()
    {
        if ($this->patch > 0)
        {
            $this->setPatch($this->patch - 1);
        }
        return $this;
    }

    /**
     * Set the patch version to a custom value.
     *
     * @param int $value Positive integer value
     *
     * @return Version This Version object
     */
    public function setPatch($value)
    {
        $this->patch = $value;
        $this->preRelease = null;

        return $this;
    }

    /**
     * Set the pre-release string to a custom value.
     *
     * @param string $value A new pre-release value
     *
     * @return Version This Version object
     */
    public function setPreRelease($value)
    {
        $this->preRelease = $value;

        return $this;
    }

    /**
     * Set the build string to a custom value.
     *
     * @param string $value A new build value
     *
     * @return Version This Version object
     */
    public function setBuild($value)
    {
        $this->build = $value;

        return $this;
    }

    /**
     * Check if this Version object is greater than another.
     *
     * @param Version $version An instance of SemVer/Version
     *
     * @return bool True if this Version object is greater than the comparing
     *              object, otherwise false
     */
    public function gt(Version $version)
    {
        if ($this->major > $version->major) {
            return true;
        }

        if ($this->major == $version->major
            && $this->minor > $version->minor
        ) {
            return true;
        }

        if ($this->major == $version->major
            && $this->minor == $version->minor
            && $this->patch > $version->patch
        ) {
            return true;
        }

        // TODO: Check pre-release tag

        return false;
    }

    /**
     * Check if this Version object is less than another.
     *
     * @param Version $version An instance of SemVer/Version
     *
     * @return bool True if this Version object is less than the comparing
     *              object, otherwise false
     */
    public function lt(Version $version)
    {
        if ($this->major < $version->major) {
            return true;
        }

        if ($this->major == $version->major
            && $this->minor < $version->minor
        ) {
            return true;
        }

        if ($this->major == $version->major
            && $this->minor == $version->minor
            && $this->patch < $version->patch
        ) {
            return true;
        }

        // TODO: Check pre-release tag

        return false;
    }

    /**
     * Check if this Version object is equal to than another.
     *
     * @param Version $version An instance of SemVer/Version
     *
     * @return bool True if this Version object is equal to the comparing
     *              object, otherwise false
     */
    public function eq(Version $version)
    {
        return $this == $version;
    }

    /**
     * Check if this Version object is not equal to another.
     *
     * @param Version $version An instance of SemVer/Version
     *
     * @return bool True if this Version object is not equal to the comparing
     *              object, otherwise false
     */
    public function neq(Version $version)
    {
        return $this != $version;
    }

    /**
     * Check if this Version object is greater than or equal to another.
     *
     * @param Version $version An instance of SemVer/Version
     *
     * @return bool True if this Version object is greater than or equal to the
     *              comparing object, otherwise false
     */
    public function gte(Version $version)
    {
        if ($this->gt($version) || $this->eq($version)) {
            return true;
        }

        return false;
    }

    /**
     * Check if this Version object is less than or equal to another.
     *
     * @param Version $version An instance of SemVer/Version
     *
     * @return bool True if this Version object is less than or equal to the
     *              comparing object, otherwise false
     */
    public function lte(Version $version)
    {
        if ($this->lt($version) || $this->eq($version)) {
            return true;
        }

        return false;
    }
}
