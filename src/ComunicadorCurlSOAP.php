<?php

/*
 * Copyright 2016 Denys.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace TIExpert\WSBoletoSantander;

/**
 * Classe que trata a comunicação entre a extensão Curl e um serviço SOAP de registro de boletos no Santander
 *
 * @author Denys Xavier <equipe@tiexpert.net>
 */
class ComunicadorCurlSOAP {

    public $configExternas;

    public function __construct($config)
    {
        $this->configExternas = $config;
    }

    /** Executa uma comunicação com o endpoint enviando uma string em formato XML
     * 
     * @param string $endpoint Url que deve ser atingida para executar a ação do WebService
     * @param array $endpointConfig Array contendo os parâmetros de configurações a serem usados na execução do cURL para que ele alcance o $endpoint
     * @return string
     * @throws Exception
     */
    public function chamar($endpoint, $endpointConfig) {
        if($endpoint == BoletoSantanderServico::COBRANCA_ENDPOINT) {

            //$endpointConfig[10015] = str_replace('xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"', 'xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:impl="http://impl.webservice.v4.ymb.app.bsbr.altec.com/"', $endpointConfig[10015]);
            $endpointConfig[10015] = str_replace('xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"', 'xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:impl="http://impl.webservice.v5.ymb.app.bsbr.altec.com/"', $endpointConfig[10015]);

            $endpointConfig[10023][2] = 'Content-length: ' . strlen($endpointConfig[10015]);

        }
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);

        curl_setopt_array($ch, $endpointConfig);

        $response = curl_exec($ch);

        if (!$response) {
            $error_message = curl_error($ch);
            curl_close($ch);

            throw new \Exception($error_message);
        }

        curl_close($ch);

        return $response;
    }

    /** Gera um array contendo todas as configurações e parâmetros necessários para um recurso de cURL
     * 
     * @param string $conteudoPost String no formato XML contendo os dados que devem ser informados ao WebService
     * @return array
     */
    public function prepararConfiguracaoEndpoint($conteudoPost = "") {
        $arrayConfig = array();

        $this->criarCabecalhosHTTP($arrayConfig, $conteudoPost);
        $this->configurarCertificadosDeSeguranca($arrayConfig);

        $arrayConfig[CURLOPT_TIMEOUT] = Config::getInstance()->getGeral("timeout");
        $arrayConfig[CURLOPT_RETURNTRANSFER] = true;

        return $arrayConfig;
    }

    /** Cria os cabeçalhos HTTP para envio de informações via POST para o endpoint.
     * 
     * @param array $arrayConfig Array contendo as configurações atuais do cURL
     * @param string $conteudoPost Conteúdo que será enviado ao endpoint
     */
    public function criarCabecalhosHTTP(&$arrayConfig, $conteudoPost) {
        $arrayConfig[CURLOPT_POST] = true;
        $arrayConfig[CURLOPT_POSTFIELDS] = $conteudoPost;

        $headers = array("Content-type: text/xml;charset=\"utf-8\"",
            "Accept: text/xml",
            "Content-length: " . strlen($conteudoPost));

        $arrayConfig[CURLOPT_HTTPHEADER] = $headers;
    }

    /** Configura e anexa os certificados digitais a serem usados durante a comunicação entre a origem e o endpoint
     * 
     * @param array $arrayConfig Array contendo as configurações atuais do cURL
     */
    public function configurarCertificadosDeSeguranca(&$arrayConfig) {
        $conf = Config::getInstance($this->configExternas);

        $arrayConfig[CURLOPT_SSL_VERIFYPEER] = $conf->getGeral("assegurar_endpoint");
        $arrayConfig[CURLOPT_SSL_VERIFYHOST] = $conf->getGeral("assegurar_endpoint") ? 2 : 0;

        $arrayConfig[CURLOPT_SSLCERT] = $conf->getCertificado("arquivo");
        $arrayConfig[CURLOPT_SSLCERTPASSWD] = $conf->getCertificado("senha");

        if ((bool) $conf->getGeral("assegurar_endpoint")) {
            if ($conf->getCertificado("arquivo_ca") != "") {
                $arrayConfig[CURLOPT_CAINFO] = $conf->getCertificado("arquivo_ca");
            }

            if ($conf->getCertificado("diretorio_cas") != "") {
                $arrayConfig[CURLOPT_CAPATH] = $conf->getCertificado("diretorio_cas");
            }
        }
    }

    /** Indica se a resposta de uma chamada ao endpoint pode ser um SOAP Fault que está formatado como string
     * 
     * @param string $response String de resposta a ser analisada
     * @return boolean
     */
    public function ehSOAPFaultComoString($response) {
        $isFault = false;

        $hasTags = preg_match("/[<>]/i", $response);

        if ($hasTags === 0) {
            $isFault = preg_match("/(?=.*faultcode)(?=.*faultstring)/i", $response) >= 1;
        }

        return $isFault;
    }

    /** Tenta converter uma resposta de uma chamada ao endpoint que é um SOAP Fault formatado como string para um Exception
     * 
     * @param string $string Resposta da chamada ao endpoint
     * @return \Exception
     * @throws \InvalidArgumentException
     */
    public function converterSOAPFaultStringParaException($string) {
        if ($this->ehSOAPFaultComoString($string)) {
            $variaveis = explode("&", $string);

            foreach ($variaveis as $variavel) {
                list($chave, $valor) = explode("=", $variavel);

                if ($chave == "faultstring") {
                    return new \Exception($valor);
                }
            }
        }
        throw new \InvalidArgumentException("O parâmetro informado não é um SOAP Fault em formato de string.");
    }

}
