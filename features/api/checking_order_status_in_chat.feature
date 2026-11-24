@agent001_order_lookup
Feature: Checking the order status in the chat
    In order to know where my order is
    As a Customer
    I want the store assistant to check my order status

    Background:
        Given the store operates on a single channel in "United States"
        And the store is connected with Agent001
        And the "United States" channel has an Agent001 agent assigned
        And there is a customer "jane@example.com" that placed an order "#00000022"

    @api
    Scenario: Checking the status of my order
        When the chat widget asks for the status of the order "#00000022" placed with "jane@example.com"
        Then the order status should be returned
        And the response should not reveal the customer

    @api
    Scenario: Not revealing an order to someone with another email address
        When the chat widget asks for the status of the order "#00000022" placed with "john@example.com"
        Then the order should not be found
