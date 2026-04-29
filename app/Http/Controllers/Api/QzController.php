<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class QzController extends Controller
{
    /**
     * Retourne le certificat public pour QZ Tray
     */
    public function getCertificate()
    {
        $certPath = storage_path('app/qz/digital-certificate.txt');
        
        if (!file_exists($certPath)) {
            return response('Certificats non trouvé. Veuillez générer les clés.', 404);
        } 

        return file_get_contents($certPath);
    }

    /**
     * Signe le message envoyé par QZ Tray
     */
    public function sign(Request $request)
    {
        $request->validate([
            'request' => 'required|string'
        ]);

        $keyPath = storage_path('app/qz/private-key.pem');

        if (!file_exists($keyPath)) {
            return response()->json(['error' => 'Clé privée non trouvée.'], 500);
        }

        $privateKey = file_get_contents($keyPath);
        $data = $request->input('request');

        $signature = '';
        if (openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA1)) {
            return base64_encode($signature);
        }

        return response()->json(['error' => 'Échec de la signature.'], 500);
    }
}
