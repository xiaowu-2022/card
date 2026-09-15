<?php

use App\Infrastructure\Sms\AliyunAcs3Signer;

it('matches the official ACS3 signing example exactly', function (): void {
    $signer = new AliyunAcs3Signer;
    $authorization = $signer->authorization('POST', '/', [
        'RegionId' => 'cn-shanghai', 'ImageId' => 'win2019_1809_x64_dtc_zh-cn_40G_alibase_20230811.vhd',
    ], [
        'x-acs-version' => '2014-05-26',
        'x-acs-signature-nonce' => '3156853299f313e23d1673dc12e1703d',
        'x-acs-date' => '2023-10-26T10:22:32Z',
        'x-acs-content-sha256' => hash('sha256', ''),
        'x-acs-action' => 'RunInstances',
        'host' => 'ecs.cn-shanghai.aliyuncs.com',
    ], 'YourAccessKeyId', 'YourAccessKeySecret');
    expect($authorization)->toBe('ACS3-HMAC-SHA256 Credential=YourAccessKeyId,SignedHeaders=host;x-acs-action;x-acs-content-sha256;x-acs-date;x-acs-signature-nonce;x-acs-version,Signature=06563a9e1b43f5dfe96b81484da74bceab24a1d853912eee15083a6f0f3283c0');
});

it('uses deterministic RFC3986 query encoding for Chinese signatures and OTP JSON', function (): void {
    expect((new AliyunAcs3Signer)->queryString(['TemplateParam' => '{"code":"123456"}', 'SignName' => '短信 签名', 'PhoneNumbers' => '8613800138000']))
        ->toBe('PhoneNumbers=8613800138000&SignName=%E7%9F%AD%E4%BF%A1%20%E7%AD%BE%E5%90%8D&TemplateParam=%7B%22code%22%3A%22123456%22%7D');
});
