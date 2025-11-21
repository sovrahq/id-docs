export const sovraTemplateCredential = ({ givenName, familyName, userId }: { givenName: string, familyName: string, userId: string }) => {
    return {
        credential: {
            '@context': [
                "https://www.w3.org/2018/credentials/v1",
                'https://www.w3.org/2018/credentials/examples/v1',
                'https://w3id.org/security/bbs/v1',
                {
                    userId: {
                        '@id': 'https://example.org/vocab#userId',
                        '@type': 'xsd:string'
                    }
                }
            ],
            type: ['VerifiableCredential'],
            expirationDate: '2027-11-18T22:09:31.350Z',
            credentialSubject: {
                givenName: givenName,
                familyName: familyName,
                userId: userId
            }
        },
        outputDescriptor: {
            id: 'credential_output',
            schema: 'https://example.com/schema',
            display: {
                title: {
                    text: 'Credencial de Identidad'
                },
                subtitle: {
                    text: 'Sovra ID'
                },
                description: {
                    text: 'Credencial de Identidad para Iniciar Sesión'
                },
                properties: [
                    {
                        path: [
                            "$.credentialSubject.givenName"
                        ],
                        fallback: 'Unknown',
                        label: 'Nombre(s)',
                        schema: {
                            type: 'string'
                        }
                    },
                    {
                        path: [
                            "$.credentialSubject.familyName"
                        ],
                        fallback: 'Unknown',
                        label: 'Apellido(s)',
                        schema: {
                            "type": "string"
                        }
                    },
                    {
                        path: [
                            "$.credentialSubject.userId"
                        ],
                        fallback: 'Unknown',
                        label: 'User ID',
                        schema: {
                            type: 'string'
                        }
                    },
                    {
                        path: [
                            "$.expirationDate"
                        ],
                        fallback: 'Unknown',
                        label: 'Expiration',
                        schema: {
                            type: 'string'
                        }
                    }
                ]
            },
            styles: {
                text: {
                    "color": "#ffffff"
                },
                hero: {
                    uri: "https://storage.googleapis.com/sovra_brand/BG.png",
                    alt: "Your Background Alt Text"
                },
                background: {
                    color: "#0b1f45"
                },
                thumbnail: {
                    uri: "https://storage.googleapis.com/sovra_brand/Logo.png",
                    alt: "Your Logo Alt Text"
                }
            }
        }
    }
}

export const sovraTemplateVerification = () => {
    return {
        inputDescriptors: [
            {
                id: 'VerifiableCredential',
                name: 'VerifiableCredential',
                constraints: {
                    fields: [
                        {
                            path: ['$.credentialSubject.givenName'],
                            filter: { type: 'string' },
                        },
                        {
                            path: ['$.credentialSubject.familyName'],
                            filter: { type: 'string' },
                        },
                        {
                            path: ['$.credentialSubject.userId'],
                            filter: { type: 'string' },
                        }
                    ],
                },
            },
        ],
        issuer: {
            name: 'Sovra',
            styles: {
                thumbnail: {
                    uri: 'https://storage.googleapis.com/sovra_brand/Logo.png',
                    alt: 'Sovra'
                },
                hero: {
                    uri: 'https://storage.googleapis.com/sovra_brand/BG.png',
                    alt: 'Sovra'
                },
                background: {
                    color: '#ff0000'
                },
                text: {
                    color: '#d4d400'
                },
            },
        },
    }
}