<?php

/*
 *
 *      _    _ _
 *     / \  | | |_ __ _ _   _
 *    / _ \ | | __/ _` | | | |
 *   / ___ \| | || (_| | |_| |
 *  /_/   \_\_|\__\__,_|\__, |
 *                       |___/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Original work by the PocketMine Team.
 * https://www.pocketmine.net/
 *
 * @author Altay Team
 * @link https://github.com/altayofficial
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\auth;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\JwtUtils;
use function openssl_pkey_new;
use function time;
use const OPENSSL_KEYTYPE_EC;

class AuthJwtHelperTest extends TestCase{

	private static function signedToken(mixed $audience, \OpenSSLAsymmetricKey $key) : string{
		$claims = [
			"cpk" => "",
			"leguuid" => "",
			"xname" => "",
			"mid" => "",
			"ap" => 0,
			"nid" => "",
			"nname" => "",
			"pid" => "",
			"pname" => "",
			"xid" => "",
			"exp" => time() + 3600
		];
		if($audience !== null){
			$claims["aud"] = $audience;
		}
		return JwtUtils::create(["alg" => "ES384"], $claims, $key);
	}

	private static function generateKey() : \OpenSSLAsymmetricKey{
		$key = openssl_pkey_new(["private_key_type" => OPENSSL_KEYTYPE_EC, "curve_name" => "secp384r1"]);
		self::assertNotFalse($key);
		return $key;
	}

	/**
	 * @return \Generator<string, array{mixed}>
	 */
	public static function acceptedAudienceProvider() : \Generator{
		yield "string" => [AuthJwtHelper::MOJANG_AUDIENCE];
		yield "array" => [[AuthJwtHelper::MOJANG_AUDIENCE]];
		yield "array with others" => [["api://something-else", AuthJwtHelper::MOJANG_AUDIENCE]];
	}

	/**
	 * @dataProvider acceptedAudienceProvider
	 */
	public function testAcceptsAudience(mixed $audience) : void{
		$key = self::generateKey();
		$claims = AuthJwtHelper::validateSelfSignedAuthToken(self::signedToken($audience, $key), JwtUtils::emitDerPublicKey($key), AuthJwtHelper::MOJANG_AUDIENCE);
		self::assertSame($audience, $claims->aud);
	}

	/**
	 * @return \Generator<string, array{mixed}>
	 */
	public static function rejectedAudienceProvider() : \Generator{
		yield "missing" => [null];
		yield "wrong string" => ["api://something-else"];
		yield "array without match" => [["api://something-else"]];
		yield "empty array" => [[]];
		yield "number" => [1];
	}

	/**
	 * @dataProvider rejectedAudienceProvider
	 */
	public function testRejectsAudience(mixed $audience) : void{
		$key = self::generateKey();
		$this->expectException(VerifyLoginException::class);
		$this->expectExceptionMessage("Invalid JWT audience");
		AuthJwtHelper::validateSelfSignedAuthToken(self::signedToken($audience, $key), JwtUtils::emitDerPublicKey($key), AuthJwtHelper::MOJANG_AUDIENCE);
	}
}
