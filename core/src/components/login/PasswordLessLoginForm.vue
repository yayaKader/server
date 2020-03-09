<template>
	<p>
		<input id="user"
			ref="user"
			v-model="user"
			type="text"
			name="user"
			:placeholder="t('core', 'Username or email')"
			:aria-label="t('core', 'Username or email')"
			required>

		<button v-on:click="authenticate">
			Login
		</button>

		<input type="hidden"
			name="requesttoken"
			:value="OC.requestToken">
	</p>
</template>

<script>

import {
	startAuthentication,
	finishAuthentication,
} from '../../service/WebAuthnAuthenticationService'

export default {
	name: 'PasswordLessLoginForm',
	data() {
		return {
			user: '',
		}
	},
	methods: {
		authenticate() {
			console.debug('passwordless login initiated')

			this.getAuthenticationData(this.user)
				.then(publicKey => {
					console.debug(publicKey)
					return publicKey
				})
				.then(this.sign)
				.then(this.completeAuthentication)
		},
		getAuthenticationData(uid) {
			const base64urlDecode = function(input) {
				// Replace non-url compatible chars with base64 standard chars
				input = input
					.replace(/-/g, '+')
					.replace(/_/g, '/')

				// Pad out with standard base64 required padding characters
				const pad = input.length % 4
				if (pad) {
					if (pad === 1) {
						throw new Error('InvalidLengthError: Input base64url string is the wrong length to determine padding')
					}
					input += new Array(5 - pad).join('=')
				}

				return window.atob(input)
			}

			return startAuthentication(uid)
				.then(publicKey => {
					console.debug('Obtained PublicKeyCredentialRequestOptions')

					publicKey.challenge = Uint8Array.from(base64urlDecode(publicKey.challenge), c => c.charCodeAt(0))
					publicKey.allowCredentials = publicKey.allowCredentials.map(function(data) {
						return {
							...data,
							'id': Uint8Array.from(base64urlDecode(data.id), c => c.charCodeAt(0)),
						}
					})

					console.debug('Converted PublicKeyCredentialRequestOptions')
					console.debug(publicKey)
					return publicKey
				})
				.catch(error => {
					console.debug('GOT AN ERROR WHILE OBTAINING DATA!')
					console.debug(error) // Example: timeout, interaction refused...
				})
		},
		sign(publicKey) {
			const arrayToBase64String = function(a) {
				btoa(String.fromCharCode(...a))
			}

			return navigator.credentials.get({ publicKey })
				.then(data => {
					console.debug(data)
					return {
						id: data.id,
						type: data.type,
						rawId: arrayToBase64String(new Uint8Array(data.rawId)),
						response: {
							authenticatorData: arrayToBase64String(new Uint8Array(data.response.authenticatorData)),
							clientDataJSON: arrayToBase64String(new Uint8Array(data.response.clientDataJSON)),
							signature: arrayToBase64String(new Uint8Array(data.response.signature)),
							userHandle: data.response.userHandle ? arrayToBase64String(new Uint8Array(data.response.userHandle)) : null,
						},
					}
				})
				.then(challenge => {
					console.debug(challenge)
					return challenge
				})
				.catch(error => {
					console.debug('GOT AN ERROR!')
					console.debug(error) // Example: timeout, interaction refused...
				})
		},
		completeAuthentication(challenge) {
			console.debug('TIME TO COMPLETE')
			return finishAuthentication(JSON.stringify(challenge))
				.then(data => {
					console.debug(data)
				})
				.catch(error => {
					console.debug('GOT AN ERROR WHILE SUBMITTING CHALLENGE!')
					console.debug(error) // Example: timeout, interaction refused...
				})
		},
	},
}
</script>

<style scoped>

</style>
