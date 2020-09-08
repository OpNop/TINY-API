<?php

class AuthServer implements \Jacwright\RestServer\AuthServer {

    public function isAuthenticated($classObj) {
        return false;
    }

    public function unauthenticated($path) {
		//header("WWW-Authenticate: Basic realm=\"$this->realm\"");
		throw new \Jacwright\RestServer\RestException(401, "Invalid credentials, access is denied to $path.");
    }

    public function isAuthorized($classObj, $method) {
        return false;
    }
    
    public function unauthorized($path) {
		throw new \Jacwright\RestServer\RestException(403, "You are not authorized to access $path.");
	}

}