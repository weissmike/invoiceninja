<?php
/**
 * Invoice Ninja (https://invoiceninja.com)
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2019. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://opensource.org/licenses/AAL
 */

namespace App\Helpers\Invoice;

use App\Helpers\Invoice\Balancer;
use App\Helpers\Invoice\CustomValuer;
use App\Helpers\Invoice\Discounter;
use App\Helpers\Invoice\InvoiceItemSum;
use App\Helpers\Invoice\Taxer;
use App\Utils\Traits\NumberFormatter;
use Illuminate\Support\Collection;

class InvoiceSum
{
	use Taxer;
	use Balancer;
	use CustomValuer;
	use Discounter;

	use NumberFormatter;

	protected $invoice;

	protected $settings;

	public $tax_map;

	public $invoice_item;

	public $total_taxes;

	private $total;

	private $total_discount;

	private $total_custom_values;

	private $total_tax_map;

	private $sub_total;
	/**
	 * Constructs the object with Invoice and Settings object
	 *
	 * @param      \App\Models\Invoice  $invoice   The invoice
	 */
	public function __construct($invoice, $settings)
	{
		
		$this->invoice = $invoice;

		$this->settings = $settings;

		$this->tax_map = new Collection;

	}

	public function build()
	{

		$this->calculateLineItems()
			 ->calculateDiscount()
			 ->calculateCustomValues()
			 ->calculateInvoiceTaxes()
			 ->setTaxMap()
			 ->calculateTotals()
			 ->calculateBalance()
			 ->calculatePartial();

		return $this;
	}

	private function calculateLineItems()
	{
		$this->invoice_items = new InvoiceItemSum($this->invoice, $this->settings);
		$this->invoice_items->process();
		$this->invoice->line_items = $this->invoice_items->getLineItems();
		$this->total = $this->invoice_items->getSubTotal();
		$this->setSubTotal($this->invoice_items->getSubTotal());

		return $this;
	}

	private function calculateDiscount()
	{
		$this->total_discount = $this->discount($this->invoice_items->getSubTotal());

		$this->total -= $this->total_discount;

		return $this;
	}

	private function calculateCustomValues()
	{
		$this->total_taxes += $this->valuerTax($this->invoice->custom_surcharge1, $this->invoice->custom_surcharge_taxes1);
        $this->total_custom_values += $this->valuer($this->invoice->custom_surcharge1);

		$this->total_taxes += $this->valuerTax($this->invoice->custom_surcharge2, $this->invoice->custom_surcharge_taxes2);
        $this->total_custom_values += $this->valuer($this->invoice->custom_surcharge2);

		$this->total_taxes += $this->valuerTax($this->invoice->custom_surcharge3, $this->invoice->custom_surcharge_taxes3);
        $this->total_custom_values += $this->valuer($this->invoice->custom_surcharge3);

        $this->total_taxes += $this->valuerTax($this->invoice->custom_surcharge4, $this->invoice->custom_surcharge_taxes4);
        $this->total_custom_values += $this->valuer($this->invoice->custom_surcharge4);

        $this->total += $this->total_custom_values;

        return $this;
	}

	private function calculateInvoiceTaxes()
	{

        if($this->invoice->tax_rate1 > 0){
        	$tax = $this->taxer($this->total, $this->invoice->tax_rate1);
        	$this->total_taxes += $tax;
        	$this->total_tax_map[] = ['name' => $this->invoice->tax_name1 . ' ' . $this->invoice->tax_rate1.'%', 'total' => $tax];
        }

        if($this->invoice->tax_rate2 > 0){
        	$tax = $this->taxer($this->total, $this->invoice->tax_rate2);
        	$this->total_taxes += $tax;
        	$this->total_tax_map[] = ['name' => $this->invoice->tax_name2. ' ' . $this->invoice->tax_rate2.'%', 'total' => $tax];
        }

        if($this->invoice->tax_rate3 > 0){
        	$tax = $this->taxer($this->total, $this->invoice->tax_rate3);
        	$this->total_taxes += $tax;
        	$this->total_tax_map[] = ['name' => $this->invoice->tax_name3 . ' ' . $this->invoice->tax_rate3.'%', 'total' => $tax];
        }

        return $this;
	}

	/**
	 * Calculates the balance.
	 * 
	 * @return     self  The balance.
	 */
	private function calculateBalance()
	{
		//$this->invoice->balance = $this->balance($this->getTotal(), $this->invoice);
		$this->setCalculatedAttributes();

		return $this;
	}

	private function calculatePartial()
	{
		if ( !isset($this->invoice->id) && isset($this->invoice->partial) ) {
            $this->invoice->partial = max(0, min($this->formatValue($this->invoice->partial, 2), $this->invoice->balance));
        }

        return $this;
	}

	private function calculateTotals()
	{
		$this->total += $this->total_taxes;

        return $this;
	}

	public function getInvoice()
	{
		//Build invoice values here and return Invoice
		$this->setCalculatedAttributes();
		$this->invoice->save();
		
		return $this->invoice;
	}


	/**
	 * Build $this->invoice variables after
	 * calculations have been performed.
	 */
	private function setCalculatedAttributes()
	{
		/* If amount != balance then some money has been paid on the invoice, need to subtract this difference from the total to set the new balance */
		if($this->invoice->amount != $this->invoice->balance)
		{
			$paid_to_date = $this->invoice->amount - $this->invoice->balance;

			$this->invoice->balance = $this->formatValue($this->getTotal(), $this->invoice->client->currency()->precision) - $paid_to_date;
		}
		else
			$this->invoice->balance = $this->formatValue($this->getTotal(), $this->invoice->client->currency()->precision);

		/* Set new calculated total */
		$this->invoice->amount = $this->formatValue($this->getTotal(), $this->invoice->client->currency()->precision);

		return $this;
	}

	public function getSubTotal()
	{
		return $this->sub_total;
	}

	public function setSubTotal($value)
	{
		$this->sub_total = $value;
		return $this;
	}

	public function getTotalDiscount()
	{
		return $this->total_discount;
	}

	public function getTotalTaxes()
	{
		return $this->total_taxes;
	}

	public function getTotalTaxMap()
	{
		return $this->total_tax_map;
	}

	public function getTotal()
	{
		return $this->total;
	}

	public function setTaxMap()
	{
		if($this->invoice->is_amount_discount == true)
			$this->invoice_items->calcTaxesWithAmountDiscount();

		$this->tax_map = collect();

        $keys = $this->invoice_items->getGroupedTaxes()->pluck('key')->unique();

        $values = $this->invoice_items->getGroupedTaxes();

        foreach($keys as $key)
        {

            $tax_name = $values->filter(function ($value, $k) use($key){
                return $value['key'] == $key;
            })->pluck('tax_name')->first();

            $total_line_tax = $values->filter(function ($value, $k) use($key){
                return $value['key'] == $key;
            })->sum('total');

            //$total_line_tax -= $this->discount($total_line_tax);

            $this->tax_map[] = ['name' => $tax_name, 'total' => $total_line_tax];

            $this->total_taxes += $total_line_tax;
        }

        return $this;
   
	}

	public function getTaxMap()
	{
		return $this->tax_map;
	}

	public function getBalance()
	{
		return $this->invoice->balance;
	}

	public function getItemTotalTaxes()
	{
		return $this->getTotalTaxes();
	}
}