<?php

use Buy\Paddle;
use Buy\Passthrough;
use Buy\Product;
use Kirby\Cms\Page;

return [
	[
		'pattern' => '.well-known/security.txt',
		'action'  => function () {
			go('security.txt');
		}
	],
	[
		'pattern' => 'hooks/clean',
		'method'  => 'GET|POST',
		'action'  => function () {
			$key = option('keys.hooks');

			if (empty($key) === false && get('key') === $key) {
				kirby()->cache('diffs')->flush();
				kirby()->cache('meet')->flush();
				kirby()->cache('pages')->flush();
				kirby()->cache('plugins')->flush();
				kirby()->cache('reference')->flush();
			}

			go();
		}
	],
	[
		'pattern' => 'releases/(:num)\-(:any)',
		'action'  => function ($generation, $major) {
			return go('releases/' . $generation . '.' . $major);
		}
	],
	[
		'pattern' => 'releases/(:num)\.(:any)',
		'action'  => function ($generation, $major) {
			return page('releases/' . $generation . '-' . $major);
		}
	],
	[
		'pattern' => 'releases/(:num)\.(:any)/(:all?)',
		'action'  => function ($generation, $major, $path) {
			return page('releases/' . $generation . '-' . $major . '/' . $path);
		}
	],
	[
		'pattern' => 'buy/prices',
		'action' => function () {
			// uncomment to test a specific country
			// Buy\Paddle::visitor(country: 'US');

			$basic      = Product::Basic;
			$enterprise = Product::Enterprise;
			$visitor    = Paddle::visitor();

			return json_encode([
				'status'   => $visitor->error() ?? 'OK',
				'country'  => $visitor->country(),
				'currency' => $visitor->currencySign(),
				'prices' => [
					'basic' => [
						'regular' => $basic->price()->regular(),
						'sale'    => $basic->price()->sale()
					],
					'donation' => [
						'customer' => $basic->price()->customerDonation(),
						'team'     => $basic->price()->teamDonation(),
					],
					'enterprise' => [
						'regular' => $enterprise->price()->regular(),
						'sale'    => $enterprise->price()->sale()
					],
				],
				'revenueLimit' => $visitor->currency() !== 'EUR' ? ' (' . $visitor->revenueLimit(1000000) . ')' : '',
				'vatRate'      => $visitor->vatRate() ?? 0,
			], JSON_UNESCAPED_UNICODE);
		}
	],
	[
		'pattern' => 'buy',
		'method'  => 'POST',
		'action' => function () {
			$city       = get('city');
			$company    = get('company');
			$country    = get('country');
			$donate     = get('donate') === 'on';
			$email      = get('email');
			$productId  = get('product');
			$newsletter = get('newsletter') === 'on';
			$postalCode = get('postalCode');
			$state      = get('state');
			$street     = get('street');
			$quantity   = (int)get('quantity', 1);
			$vatId      = get('vatId');

			try {
				$product     = Product::from($productId);
				$price       = $product->price();
				$message     = $product->revenueLimit();
				$passthrough = new Passthrough(teamDonation: option('buy.donation.teamAmount') * $quantity);

				$eurPrice       = $product->price('EUR')->volume($quantity);
				$localizedPrice = $price->volume($quantity);

				if ($donate === true) {
					// prices per license
					$customerDonation = option('buy.donation.customerAmount');
					$eurPrice       += $customerDonation;
					$localizedPrice += $price->convert($customerDonation);

					// donation overall
					$customerDonation *= $quantity;
					$passthrough->customerDonation = $customerDonation;

					$message .= ' We will donate an additional €' . $customerDonation . ' to ' . option('buy.donation.charity') . '. Thank you for your donation!';
				}

				$prices  = [
					'EUR:' . $eurPrice,
					$price->currency . ':' . $localizedPrice,
				];

				go($product->checkout('buy', [
					'custom_message'    => $message,
					'customer_country'  => $country,
					'customer_email'    => $email,
					'customer_postcode' => $postalCode,
					'marketing_consent' => $newsletter ? 1 : 0,
					'passthrough'       => $passthrough,
					'prices'            => $prices,
					'quantity'          => $quantity,
					'vat_city'          => $city,
					'vat_country'       => $country,
					'vat_company_name'  => $company,
					'vat_number'        => $vatId,
					'vat_postcode'      => $postalCode,
					'vat_state'         => $state,
					'vat_street'        => $street,
				]));
			} catch (Throwable $e) {
				die($e->getMessage() . '<br>Please contact us: support@getkirby.com');
			}
		},
	],
	[
		'pattern' => 'buy/(enterprise|basic)',
		'action' => function (string $productId) {
			try {
				$product     = Product::from($productId);
				$price       = $product->price();
				$passthrough = new Passthrough(teamDonation: option('buy.donation.teamAmount'));

				$eurPrice       = $product->price('EUR')->sale();
				$localizedPrice = $price->sale();

				$prices  = [
					'EUR:' . $eurPrice,
					$price->currency . ':' . $localizedPrice,
				];

				go($product->checkout('buy', compact('prices', 'passthrough')));
			} catch (Throwable $e) {
				die($e->getMessage() . '<br>Please contact us: support@getkirby.com');
			}
		},
	],
	[
		'pattern' => 'buy/volume',
		'method'  => 'POST',
		'action'  => function () {
			$productId = get('product', 'basic');
			$quantity  = (int)get('volume', 5);

			try {
				$product     = Product::from($productId);
				$price       = $product->price();
				$passthrough = new Passthrough(teamDonation: option('buy.donation.teamAmount') * $quantity);

				$eurPrice       = $product->price('EUR')->volume($quantity);
				$localizedPrice = $price->volume($quantity);

				$prices  = [
					'EUR:' . $eurPrice,
					$price->currency . ':' . $localizedPrice,
				];

				go($product->checkout('buy', compact('prices', 'quantity', 'passthrough')));
			} catch (Throwable $e) {
				die($e->getMessage() . '<br>Please contact us: support@getkirby.com');
			}
		}
	],
	[
		'pattern' => 'buy/volume/(enterprise|basic)/(:num)',
		'action'  => function (string $productId, int $quantity) {
			try {
				$product     = Product::from($productId);
				$price       = $product->price();
				$passthrough = new Passthrough(teamDonation: option('buy.donation.teamAmount') * $quantity);

				$prices  = [
					'EUR:' . $product->price('EUR')->volume($quantity),
					$price->currency . ':' . $price->volume($quantity),
				];

				go($product->checkout('buy', compact('prices', 'quantity', 'passthrough')));
			} catch (Throwable $e) {
				die($e->getMessage() . '<br>Please contact us: support@getkirby.com');
			}
		}
	],
	[
		'pattern' => 'pixels',
		'action'  => function () {
			return new Page([
				'slug'     => 'pixels',
				'template' => 'pixels',
				'content'  => [
					'title' => 'Pixels'
				]
			]);
		}
	],
	[
		'pattern' => 'plugins/k4',
		'action'  => function () {
			return page('plugins')->render(['filter' => 'k4']);
		}
	],
	[
		'pattern' => 'plugins/new',
		'action'  => function () {
			return page('plugins')->render(['filter' => 'published']);
		}
	],
];
