<?php

namespace ReactphpX\TunnelStream;

use Laravel\SerializableClosure\SerializableClosure as LaravelSerializableClosure;

class SerializableClosure
{
    public static function serialize($closure, $secretKey = '')
    {
        if ($secretKey) {
            LaravelSerializableClosure::setSecretKey($secretKey);
        }

        $seralized = serialize(new LaravelSerializableClosure($closure));
        LaravelSerializableClosure::setSecretKey(null);

        return $seralized;
    }

    public static function unserialize($serialized, $secretKey = '')
    {
        if ($secretKey) {
            LaravelSerializableClosure::setSecretKey($secretKey);
        }
        $closure = unserialize($serialized)->getClosure();
        LaravelSerializableClosure::setSecretKey(null);
        return $closure;
    }
}
